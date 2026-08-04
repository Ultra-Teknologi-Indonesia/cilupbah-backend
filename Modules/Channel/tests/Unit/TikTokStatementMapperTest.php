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

        // commission termasuk dynamic_commission (khusus ID), positif (abs).
        $this->assertSame(3000.0, $result['commission_fee']);
        $this->assertSame(300.0, $result['service_fee']);
        $this->assertSame(500.0, $result['transaction_fee']);
        // gross dari subtotal_before_discount.
        $this->assertSame(100000.0, $result['gross_amount']);
        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(1000.0, $result['total_tax']);
        $this->assertSame(90000.0, $result['settlement_amount']);
        // shipping_cost_amount order-level negatif → ditanggung seller.
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
}
