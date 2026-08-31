<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\ShopeeToInternalOrderMapper;
use Modules\Channel\Tests\Support\SeedsCatalogVariant;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ShopeeOrderSyncTest extends TestCase
{
    use RefreshDatabase;
    use SeedsCatalogVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCatalogVariant('SKU-TAS');

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
        $mapper = new ShopeeToInternalOrderMapper;

        $internal = $mapper->map($this->orderDetail(['order_status' => 'READY_TO_SHIP']), '778899');

        $this->assertEquals('2606SHOPEE01', $internal['channel_order_no']);
        $this->assertEquals('shopee', $internal['source']);
        $this->assertEquals('AWAITING_SHIPMENT', $internal['channel_status']);
        $this->assertTrue($internal['is_paid']);
        $this->assertCount(1, $internal['items']);
        $this->assertEquals(2, $internal['items'][0]['qty_in_base']);
        $this->assertEquals(120000.0, $internal['items'][0]['amount']);
    }

    public function test_return_status_does_not_create_buyer_cancel_request(): void
    {
        $mapper = new ShopeeToInternalOrderMapper;

        $internal = $mapper->map($this->orderDetail([
            'order_status' => 'TO_RETURN',
            'buyer_cancel_reason' => 'buyer_changed_mind',
        ]), '778899');

        $this->assertSame('TO_RETURN', $internal['channel_status']);
        $this->assertNull($internal['cancel_requested_at']);
        $this->assertNull($internal['cancel_request_reason']);
    }

    public function test_in_cancel_creates_buyer_cancel_request(): void
    {
        $mapper = new ShopeeToInternalOrderMapper;

        $internal = $mapper->map($this->orderDetail([
            'order_status' => 'IN_CANCEL',
            'buyer_cancel_reason' => 'buyer_changed_mind',
        ]), '778899');

        $this->assertSame('IN_CANCEL', $internal['channel_status']);
        $this->assertNotNull($internal['cancel_requested_at']);
        $this->assertSame('buyer_changed_mind', $internal['cancel_request_reason']);
    }

    public function test_mapper_derives_discount_from_original_vs_discounted_price(): void
    {
        $mapper = new ShopeeToInternalOrderMapper;

        $internal = $mapper->map($this->orderDetail([
            'item_list' => [[
                'item_id' => 555100,
                'item_name' => 'Tas Kanvas',
                'model_sku' => 'SKU-TAS',
                'model_quantity_purchased' => 2,
                'model_original_price' => 100000,
                'model_discounted_price' => 60000,
            ]],
        ]), '778899');

        $item = $internal['items'][0];
        $this->assertEquals(100000.0, $item['price']);
        $this->assertEquals(40000.0, $item['disc']);
        $this->assertEquals(80000.0, $item['disc_amount']);
        $this->assertEquals(120000.0, $item['amount']);
        $this->assertEquals(80000.0, $internal['total_disc']);
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

    public function test_shipping_channel_types_use_explicit_channel_categories_only(): void
    {
        Cache::forget('shopee:shipping_channel_types:778899');

        Http::fake([
            'partner.shopeemobile.com/api/v2/logistics/get_channel_list*' => Http::response([
                'response' => [
                    'logistics_channel_list' => [
                        ['logistics_channel_id' => 8001, 'logistics_channel_name' => 'Same Day', 'service_type_identifier' => 'same_day'],
                        ['logistics_channel_id' => 8003, 'logistics_channel_name' => 'Reguler', 'service_type_identifier' => 'regular'],
                        ['logistics_channel_id' => 8005, 'logistics_channel_name' => 'SPX Hemat', 'service_type_identifier' => null],
                        ['logistics_channel_id' => 80029, 'logistics_channel_name' => 'Unknown service'],
                    ],
                ],
            ], 200),
        ]);

        $service = app(ShopeeOrderService::class);

        $this->assertSame(
            ['8001' => 'SAME_DAY', '8003' => 'REGULAR', '8005' => null],
            $service->shippingChannelTypes('778899'),
        );
        $this->assertSame(['8001'], $service->instantChannelIds('778899'));
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
