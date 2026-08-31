<?php

namespace Modules\Channel\Tests\Unit;

use Modules\Channel\Services\TikTokToInternalOrderMapper;
use Tests\TestCase;

class TikTokToInternalOrderMapperTest extends TestCase
{
    private function order(array $lineItems): array
    {
        return [
            'id' => '584679547389839141',
            'status' => 'AWAITING_SHIPMENT',
            'line_items' => $lineItems,
        ];
    }

    public function test_item_sku_uses_seller_sku_when_present(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertSame('AG-17-BLU', $result['items'][0]['sku']);
    }

    public function test_item_sku_falls_back_to_tk_prefixed_sku_id_when_seller_sku_empty(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $result = $mapper->map($this->order([
            ['product_id' => '1736162610481824874', 'sku_id' => '1736162649042093162', 'seller_sku' => '', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertSame('TK-1736162649042093162', $result['items'][0]['sku']);
    }

    public function test_item_sku_is_null_when_no_seller_sku_and_no_sku_id(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertNull($result['items'][0]['sku']);
    }

    public function test_captures_seller_and_platform_voucher_estimates_from_payment(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]);
        $order['payment'] = [
            'seller_discount' => 5000,
            'platform_discount' => 3000,
        ];

        $result = $mapper->map($order, 'shop-1');

        $this->assertSame(5000.0, $result['seller_voucher']);
        $this->assertSame(3000.0, $result['platform_voucher']);
    }

    public function test_voucher_estimates_are_null_when_payment_absent(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertNull($result['seller_voucher']);
        $this->assertNull($result['platform_voucher']);
    }

    public function test_maps_tokopedia_commerce_platform_without_changing_tiktok_api_source(): void
    {
        $mapper = new TikTokToInternalOrderMapper;
        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]);
        $order['commerce_platform'] = 'TOKOPEDIA';

        $result = $mapper->map($order, 'shop-1');

        $this->assertSame('tiktok', $result['source']);
        $this->assertSame('TOKOPEDIA', $result['commerce_platform']);
    }

    public function test_defaults_missing_commerce_platform_to_tiktok_shop(): void
    {
        $mapper = new TikTokToInternalOrderMapper;

        $result = $mapper->map($this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]), 'shop-1');

        $this->assertSame('TIKTOK_SHOP', $result['commerce_platform']);
    }

    public function test_reads_instant_from_tiktok_delivery_category_not_provider_name(): void
    {
        $mapper = new TikTokToInternalOrderMapper;
        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]);
        $order['shipping_provider'] = 'J&T Express';
        $order['shipping_type'] = 'TIKTOK';
        $order['delivery_option_name'] = 'Instant Hemat';

        $result = $mapper->map($order, 'shop-1');

        $this->assertTrue($result['channel_instant']);
    }

    public function test_does_not_infer_instant_from_tiktok_provider_when_category_is_generic(): void
    {
        $mapper = new TikTokToInternalOrderMapper;
        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'seller_sku' => 'AG-17-BLU', 'quantity' => 1],
        ]);
        $order['shipping_provider'] = 'Grab Instant Hemat';
        $order['shipping_type'] = 'TIKTOK';
        $order['fulfillment_type'] = 'FULFILLMENT_BY_SELLER';

        $result = $mapper->map($order, 'shop-1');

        $this->assertNull($result['channel_instant']);
    }

    public function test_does_not_reopen_buyer_cancel_for_completed_order(): void
    {
        $mapper = new TikTokToInternalOrderMapper;
        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'quantity' => 1],
        ]);
        $order['status'] = 'COMPLETED';
        $order['cancellation_initiator'] = 'BUYER';

        $result = $mapper->map($order, 'shop-1');

        $this->assertNull($result['cancel_requested_at']);
        $this->assertNull($result['cancel_request_reason']);
    }

    public function test_maps_buyer_cancel_only_for_cancellable_order_state(): void
    {
        $mapper = new TikTokToInternalOrderMapper;
        $order = $this->order([
            ['product_id' => '173', 'sku_id' => '999', 'quantity' => 1],
        ]);
        $order['cancellation_initiator'] = 'BUYER';
        $order['cancel_reason'] = 'buyer_changed_mind';

        $result = $mapper->map($order, 'shop-1');

        $this->assertNotNull($result['cancel_requested_at']);
        $this->assertSame('buyer_changed_mind', $result['cancel_request_reason']);
    }
}
