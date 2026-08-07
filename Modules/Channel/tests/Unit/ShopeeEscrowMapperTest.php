<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\ShopeeEscrowMapper;
use Tests\TestCase;

class ShopeeEscrowMapperTest extends TestCase
{
    private function escrow(array $income): array
    {
        return ['currency' => 'IDR', 'order_income' => $income];
    }

    public function test_maps_income_to_canonical_finance(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'commission_fee'        => 2200,
            'service_fee'           => 1100,
            'seller_transaction_fee' => 800,
            'voucher_from_seller'   => 5000,
            'voucher_from_shopee'   => 3000,
            'escrow_amount'         => 98900,
        ]));

        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(3000.0, $result['platform_voucher']);
        $this->assertSame(2200.0, $result['commission_fee']);
        $this->assertSame(1100.0, $result['service_fee']);
        $this->assertSame(800.0, $result['transaction_fee']);
        $this->assertSame(98900.0, $result['settlement_amount']);
        $this->assertTrue($result['is_settled']);
        $this->assertSame('IDR', $result['fee_currency']);
    }

    public function test_seller_shipping_borne_is_actual_minus_buyer_paid_minus_rebate(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'actual_shipping_fee'    => 20000,
            'buyer_paid_shipping_fee' => 8000,
            'shopee_shipping_rebate' => 10000,
            'escrow_amount'          => 1,
        ]));

        $this->assertSame(2000.0, $result['seller_shipping_borne']);
        $this->assertSame(10000.0, $result['platform_shipping_rebate']);
    }

    public function test_maps_tax_and_insurance_when_present(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'          => 50000,
            'escrow_tax'             => 1500,
            'final_product_protection' => 2000,
        ]));

        $this->assertSame(1500.0, $result['total_tax']);
        $this->assertSame(2000.0, $result['insurance_cost']);
    }

    public function test_tax_falls_back_to_vat_components(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'         => 50000,
            'final_product_vat_tax'  => 1000,
            'final_shipping_vat_tax' => 250,
        ]));

        $this->assertSame(1250.0, $result['total_tax']);
    }

    public function test_omits_tax_and_insurance_keys_when_absent(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'commission_fee' => 2200,
            'escrow_amount'  => 50000,
        ]));

        $this->assertArrayNotHasKey('total_tax', $result);
        $this->assertArrayNotHasKey('insurance_cost', $result);
    }

    public function test_not_settled_when_escrow_amount_missing(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'commission_fee' => 2200,
        ]));

        $this->assertFalse($result['is_settled']);
        $this->assertNull($result['settlement_amount']);
    }

    public function test_fee_lines_carry_raw_channel_codes_for_non_null_components(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'commission_fee'      => 2200,
            'voucher_from_shopee' => 3000,
            'escrow_amount'       => 5000,
        ]));

        $byType = collect($result['fee_lines'])->keyBy('fee_type');

        $this->assertSame('commission_fee', $byType['commission_fee']['channel_fee_code']);
        $this->assertSame(2200.0, $byType['commission_fee']['amount']);
        $this->assertSame('voucher_from_shopee', $byType['platform_voucher']['channel_fee_code']);

        $this->assertArrayNotHasKey('service_fee', $byType->all());
    }

    public function test_real_staging_escrow_gross_and_settlement(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'               => 198000,
            'buyer_total_amount'          => 214900,
            'commission_fee'              => 0,
            'service_fee'                 => 0,
            'seller_transaction_fee'      => 0,
            'actual_shipping_fee'         => 15000,
            'buyer_paid_shipping_fee'     => 15000,
            'shopee_shipping_rebate'      => 0,
            'seller_order_processing_fee' => 0,
            'voucher_from_seller'         => 0,
            'voucher_from_shopee'         => 0,
            'original_price'              => 198000,
            'order_original_price'        => 198000,
        ]));

        $this->assertSame(0.0, $result['seller_shipping_borne']);
        $this->assertSame(198000.0, $result['settlement_amount']);

        $this->assertSame(198000.0, $result['gross_amount']);
        $this->assertSame(0.0, $result['order_processing_fee']);
        $this->assertNull($result['settled_at']);
        $this->assertTrue($result['is_settled']);
    }

    public function test_reads_seller_order_processing_fee_field(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'               => 50000,
            'seller_order_processing_fee' => 1250,
        ]));

        $this->assertSame(1250.0, $result['order_processing_fee']);
    }

    public function test_refund_total_from_seller_return_refund_and_negative_adjustment(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'          => 50000,
            'seller_return_refund'   => 12000,
            'total_adjustment_amount' => -3000,
        ]));

        $this->assertSame(15000.0, $result['refund_total']);
    }

    public function test_raw_payload_is_preserved(): void
    {
        $escrow = $this->escrow(['escrow_amount' => 1]);
        $result = (new ShopeeEscrowMapper())->map($escrow);

        $this->assertSame($escrow, $result['raw']);
    }

    public function test_gross_amount_prefers_selling_price_over_original_price(): void
    {

        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'order_original_price' => 50000,
            'order_selling_price'  => 12750,
            'escrow_amount'        => 9715,
        ]));

        $this->assertSame(12750.0, $result['gross_amount']);
    }

    public function test_gross_amount_falls_back_to_original_when_selling_absent(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'order_original_price' => 50000,
            'escrow_amount'        => 9715,
        ]));

        $this->assertSame(50000.0, $result['gross_amount']);
    }

    public function test_gross_prefers_discounted_price_and_reconciles_to_escrow(): void
    {

        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'order_selling_price'         => 230000,
            'order_discounted_price'      => 228050,
            'order_original_price'        => 600000,
            'commission_fee'              => 22805,
            'service_fee'                 => 9122,
            'seller_order_processing_fee' => 1250,
            'actual_shipping_fee'         => 36500,
            'buyer_paid_shipping_fee'     => 36500,
            'shopee_shipping_rebate'      => 0,
            'escrow_amount'               => 194873,
        ]));

        $this->assertSame(228050.0, $result['gross_amount']);

        $recon = $result['gross_amount']
            - $result['commission_fee'] - $result['service_fee'] - $result['order_processing_fee']
            - (float) $result['seller_shipping_borne'];
        $this->assertSame(194873.0, $recon);
        $this->assertSame(194873.0, $result['settlement_amount']);
    }

    public function test_withholding_tax_is_added_on_top_of_escrow_tax(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'   => 50000,
            'escrow_tax'      => 1500,
            'withholding_tax' => 95,
        ]));

        $this->assertSame(1595.0, $result['total_tax']);
    }

    public function test_withholding_tax_alone_becomes_total_tax(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'escrow_amount'   => 50000,
            'withholding_tax' => 382,
        ]));

        $this->assertSame(382.0, $result['total_tax']);
    }

    public function test_fee_lines_skip_zero_amount_components(): void
    {
        $result = (new ShopeeEscrowMapper())->map($this->escrow([
            'commission_fee'         => 3500,
            'service_fee'            => 0,
            'seller_transaction_fee' => 0,
            'voucher_from_seller'    => 0,
            'escrow_amount'          => 28675,
        ]));

        $byType = collect($result['fee_lines'])->keyBy('fee_type');

        $this->assertArrayHasKey('commission_fee', $byType->all());
        $this->assertArrayHasKey('settlement_amount', $byType->all());
        $this->assertArrayNotHasKey('service_fee', $byType->all());
        $this->assertArrayNotHasKey('transaction_fee', $byType->all());
        $this->assertArrayNotHasKey('seller_voucher', $byType->all());
    }
}
