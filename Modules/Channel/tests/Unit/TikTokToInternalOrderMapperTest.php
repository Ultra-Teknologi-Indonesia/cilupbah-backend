<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Tests\TestCase;

class TikTokToInternalOrderMapperTest extends TestCase
{
    private function order(array $lineItems): array
    {
        return [
            'id'         => '584679547389839141',
            'status'     => 'AWAITING_SHIPMENT',
            'line_items' => $lineItems,
        ];
    }

    public function test_item_sku_uses_seller_sku_when_present(): void
    {
        $mapper = new TikTokToInternalOrderMapper();

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertSame('AG-17-BLU', $result['items'][0]['sku']);
    }

    public function test_item_sku_falls_back_to_tk_prefixed_sku_id_when_seller_sku_empty(): void
    {
        $mapper = new TikTokToInternalOrderMapper();

        $result = $mapper->map($this->order([
            ['product_id' => '1736162610481824874', 'sku_id' => '1736162649042093162', 'seller_sku' => '', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertSame('TK-1736162649042093162', $result['items'][0]['sku']);
    }

    public function test_item_sku_is_null_when_no_seller_sku_and_no_sku_id(): void
    {
        $mapper = new TikTokToInternalOrderMapper();

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertNull($result['items'][0]['sku']);
    }

    public function test_captures_seller_and_platform_voucher_estimates_from_payment(): void
    {
        $mapper = new TikTokToInternalOrderMapper();

        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]);
        $order['payment'] = [
            'seller_discount'   => 5000,
            'platform_discount' => 3000,
        ];

        $result = $mapper->map($order, 'shop-1');

        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(3000.0, $result['platform_voucher']);
    }

    public function test_voucher_estimates_are_null_when_payment_absent(): void
    {
        $mapper = new TikTokToInternalOrderMapper();

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertNull($result['seller_voucher']);
        $this->assertNull($result['platform_voucher']);
    }
}
