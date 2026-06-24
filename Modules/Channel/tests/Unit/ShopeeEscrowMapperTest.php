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
        // Komponen yang null tidak menghasilkan baris.
        $this->assertArrayNotHasKey('service_fee', $byType->all());
    }
}
