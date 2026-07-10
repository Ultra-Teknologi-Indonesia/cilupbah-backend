<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ShopeeOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    private function orderDetail(array $override = []): array
    {
        return array_merge([
            'order_sn' => '2606SHOPEE01',
            'order_status' => 'UNPAID',
            'create_time' => 1760000000,
            'total_amount' => 120000,
            'buyer_username' => 'sari_buyer',
            'recipient_address' => ['name' => 'Sari', 'phone' => '0812', 'full_address' => 'Jl. Mawar', 'city' => 'Bandung'],
            'item_list' => [[
                'item_id' => 555100,
                'item_name' => 'Tas Kanvas',
                'model_sku' => 'SKU-TAS',
                'model_name' => 'Merah',
                'model_quantity_purchased' => 2,
                'model_discounted_price' => 60000,
            ]],
        ], $override);
    }

    public function test_mapper_maps_status_and_groups_items(): void
    {
        $mapper = new ShopeeToInternalOrderMapper();

        $internal = $mapper->map($this->orderDetail(['order_status' => 'READY_TO_SHIP']), '778899');

        $this->assertEquals('2606SHOPEE01', $internal['channel_order_no']);
        $this->assertEquals('shopee', $internal['source']);
        $this->assertEquals('AWAITING_SHIPMENT', $internal['channel_status']);
        $this->assertTrue($internal['is_paid']);
        $this->assertCount(1, $internal['items']);
        $this->assertEquals(2, $internal['items'][0]['qty_in_base']);
        $this->assertEquals(120000.0, $internal['items'][0]['amount']);
    }

    public function test_pull_orders_creates_sales_order_with_items(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::response([
                'response' => [
                    'order_list' => [['order_sn' => '2606SHOPEE01']],
                    'more' => false,
                    'next_cursor' => '',
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [$this->orderDetail()]],
            ], 200),
        ]);

        $count = app(ShopeeOrderService::class)->pullOrders('778899');

        $this->assertEquals(1, $count);

        $order = SalesOrder::where('salesorder_no', 'SP-2606SHOPEE01')->with('items')->first();
        $this->assertNotNull($order);
        $this->assertEquals('shopee', $order->source);
        $this->assertEquals('2606SHOPEE01', $order->channel_order_no);
        $this->assertCount(1, $order->items);
    }

    public function test_pull_orders_captures_pickup_code_from_tracking_number_response(): void
    {

        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::response([
                'response' => [
                    'order_list' => [['order_sn' => '2606SHOPEE01']],
                    'more' => false,
                    'next_cursor' => '',
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [$this->orderDetail(['order_status' => 'READY_TO_SHIP'])]],
            ], 200),
            'partner.shopeemobile.com/api/v2/logistics/get_tracking_number*' => Http::response([
                'response' => ['tracking_number' => 'SPXID123456', 'pickup_code' => '482913'],
            ], 200),
        ]);

        app(ShopeeOrderService::class)->pullOrders('778899');

        $order = SalesOrder::where('salesorder_no', 'SP-2606SHOPEE01')->first();
        $this->assertNotNull($order);
        $this->assertEquals('SPXID123456', $order->tracking_number);
        $this->assertEquals('482913', $order->pickup_code);
    }

    public function test_pull_orders_leaves_pickup_code_null_when_absent(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_list*' => Http::response([
                'response' => [
                    'order_list' => [['order_sn' => '2606SHOPEE01']],
                    'more' => false,
                    'next_cursor' => '',
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [$this->orderDetail(['order_status' => 'READY_TO_SHIP'])]],
            ], 200),
            'partner.shopeemobile.com/api/v2/logistics/get_tracking_number*' => Http::response([
                'response' => ['tracking_number' => 'SPXID999'],
            ], 200),
        ]);

        app(ShopeeOrderService::class)->pullOrders('778899');

        $order = SalesOrder::where('salesorder_no', 'SP-2606SHOPEE01')->first();
        $this->assertEquals('SPXID999', $order->tracking_number);
        $this->assertNull($order->pickup_code);
    }

    public function test_webhook_order_event_pulls_single_order(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'response' => ['order_list' => [$this->orderDetail()]],
            ], 200),
        ]);

        $payload = [
            'shop_id' => 778899,
            'code' => 3,
            'timestamp' => 1760000000,
            'data' => ['ordersn' => '2606SHOPEE01', 'status' => 'UNPAID'],
        ];

        (new ProcessShopeeWebhook($payload))->handle(
            app(ShopeeOrderService::class),
            app(ChannelDownloadService::class),
        );

        $this->assertNotNull(SalesOrder::where('salesorder_no', 'SP-2606SHOPEE01')->first());
    }
}
