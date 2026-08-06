<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\TikTokStatementMapper;
use Tests\TestCase;

class TikTokStatementMapperTest extends TestCase
{
    private function byOrderPayload(): array
    {
        return [
            'currency'             => 'IDR',
            'settlement_amount'    => '90000',
            'revenue_amount'       => '100000',
            'fee_and_tax_amount'   => '-5000',
            'shipping_cost_amount' => '-5000',
            'sku_transactions'     => [[
                'revenue_breakdown' => [
                    'subtotal_before_discount_amount'        => '100000',
                    'seller_discount_amount'                 => '-5000',
                    'refund_subtotal_before_discount_amount' => '0',
                    'seller_discount_refund_amount'          => '0',
                ],
                'shipping_cost_amount' => '-5000',
                'fee_tax_breakdown'    => [
                    'fee' => [
                        'platform_commission_amount' => '-2200',
                        'dynamic_commission_amount'  => '-800',
                        'transaction_fee_amount'     => '-500',
                        'sfp_service_fee_amount'     => '-300',
                    ],
                    'tax' => [
                        'local_vat_amount' => '-1000',
                    ],
                ],
            ]],
        ];
    }

    public function test_maps_nested_202501_by_order_and_makes_fees_positive(): void
    {
        $result = (new TikTokStatementMapper())->map($this->byOrderPayload());

        $this->assertSame(3000.0, $result['commission_fee']);
        $this->assertSame(300.0, $result['service_fee']);
        $this->assertSame(500.0, $result['transaction_fee']);

        $this->assertSame(100000.0, $result['gross_amount']);
        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(1000.0, $result['total_tax']);
        $this->assertSame(90000.0, $result['settlement_amount']);

        $this->assertSame(5000.0, $result['seller_shipping_borne']);
        $this->assertTrue($result['is_settled']);
        $this->assertSame('IDR', $result['fee_currency']);
    }

    public function test_fee_lines_are_signed_negative_for_deductions(): void
    {
        $result = (new TikTokStatementMapper())->map($this->byOrderPayload());
        $byType = collect($result['fee_lines'])->keyBy('fee_type');

        $this->assertSame('platform_commission_amount', $byType['commission_fee']['channel_fee_code']);
        $this->assertSame(-3000.0, $byType['commission_fee']['amount']);
    }

    public function test_supports_by_statement_single_transaction_form(): void
    {
        $result = (new TikTokStatementMapper())->map([
            'currency'          => 'IDR',
            'order_id'          => '576123',
            'settlement_amount' => '40000',
            'revenue_amount'    => '45000',
            'revenue_breakdown' => [
                'subtotal_before_discount_amount' => '45000',
            ],
            'fee_tax_breakdown' => [
                'fee' => ['platform_commission_amount' => '-1500'],
                'tax' => [],
            ],
        ]);

        $this->assertSame(1500.0, $result['commission_fee']);
        $this->assertSame(45000.0, $result['gross_amount']);
        $this->assertSame(40000.0, $result['settlement_amount']);
        $this->assertTrue($result['is_settled']);
    }

    public function test_empty_and_zero_settlement_is_not_settled(): void
    {
        $result = (new TikTokStatementMapper())->map(['settlement_amount' => '0', 'sku_transactions' => []]);

        $this->assertFalse($result['is_settled']);
        $this->assertArrayHasKey('raw', $result);
    }

    public function test_uncategorized_fee_captured_as_other_fee_residual(): void
    {
        // fee_tax_amount total = -10250, tapi hanya 9000 yang masuk kategori baku;
        // sisa 1250 (vn_fix_infrastructure_fee) harus jadi other_fee agar breakdown rekonsiliasi.
        $result = (new TikTokStatementMapper())->map([
            'currency'          => 'IDR',
            'settlement_amount' => '34750',
            'revenue_amount'    => '45000',
            'fee_tax_amount'    => '-10250',
            'shipping_cost_amount' => '0',
            'sku_transactions'  => [[
                'revenue_breakdown' => [
                    'subtotal_before_discount_amount' => '100000',
                    'seller_discount_amount'          => '-55000',
                ],
                'fee_tax_breakdown' => [
                    'fee' => [
                        'platform_commission_amount'        => '-4365',
                        'dynamic_commission_amount'         => '-1800',
                        'mall_service_fee_amount'           => '-810',
                        'bonus_cashback_service_fee_amount' => '-2025',
                        'vn_fix_infrastructure_fee'         => '-1250', // di luar whitelist
                    ],
                    'tax' => [],
                ],
            ]],
        ]);

        $this->assertSame(6165.0, $result['commission_fee']);
        $this->assertSame(2835.0, $result['service_fee']);
        $this->assertSame(1250.0, $result['other_fee']);

        // gross - voucher - komisi - service - other = net
        $recon = $result['gross_amount'] - $result['seller_voucher']
            - $result['commission_fee'] - $result['service_fee'] - $result['other_fee'];
        $this->assertSame(34750.0, $recon);
    }

    public function test_fully_refunded_order_nets_returned_voucher(): void
    {
        // Order refund-penuh: voucher yang dikembalikan (seller_discount_refund) mengurangi refund bersih.
        $result = (new TikTokStatementMapper())->map([
            'currency'          => 'IDR',
            'settlement_amount' => '0',
            'revenue_amount'    => '0',
            'fee_tax_amount'    => '0',
            'shipping_cost_amount' => '0',
            'sku_transactions'  => [[
                'revenue_breakdown' => [
                    'subtotal_before_discount_amount'        => '100000',
                    'seller_discount_amount'                 => '-70000',
                    'seller_discount_refund_amount'          => '70000',
                    'refund_subtotal_before_discount_amount' => '-100000',
                ],
                'fee_tax_breakdown' => ['fee' => [], 'tax' => []],
            ]],
        ]);

        $this->assertSame(100000.0, $result['gross_amount']);
        $this->assertSame(70000.0, $result['seller_voucher']);
        $this->assertSame(30000.0, $result['refund_total']); // 100000 - 70000
        $this->assertSame(0.0, $result['gross_amount'] - $result['seller_voucher'] - $result['refund_total']);
    }

    public function test_positive_net_shipping_becomes_rebate(): void
    {
        $result = (new TikTokStatementMapper())->map([
            'currency'             => 'IDR',
            'settlement_amount'    => '48000',
            'revenue_amount'       => '45000',
            'fee_tax_amount'       => '0',
            'shipping_cost_amount' => '3000', // net positif = subsidi/rebate bersih
            'sku_transactions'     => [[
                'revenue_breakdown' => ['subtotal_before_discount_amount' => '45000'],
                'fee_tax_breakdown' => ['fee' => [], 'tax' => []],
            ]],
        ]);

        $this->assertSame(3000.0, $result['platform_shipping_rebate']);
        $this->assertNull($result['seller_shipping_borne']);
    }
}
