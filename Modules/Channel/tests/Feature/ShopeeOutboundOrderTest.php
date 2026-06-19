<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

class ShopeeOutboundOrderTest extends TestCase
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

    private function fakeOrderDetail(string $status = 'UNPAID'): array
    {
        return [
            'response' => ['order_list' => [[
                'order_sn' => '2606SHOPEE01',
                'order_status' => $status,
                'create_time' => 1760000000,
                'total_amount' => 60000,
                'item_list' => [['item_id' => 555100, 'model_id' => 12, 'model_sku' => 'SKU-A', 'model_quantity_purchased' => 1, 'model_discounted_price' => 60000]],
            ]]],
        ];
    }

    public function test_ship_order_uses_pickup_parameter(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/logistics/get_shipping_parameter*' => Http::response([
                'response' => ['pickup' => ['address_list' => [['address_id' => 123, 'time_slot_list' => [['pickup_time_id' => 'slot-1']]]]]],
            ], 200),
            'partner.shopeemobile.com/api/v2/logistics/ship_order*' => Http::response(['response' => []], 200),
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail('READY_TO_SHIP'), 200),
        ]);

        $result = app(ShopeeOrderService::class)->shipOrder('778899', '2606SHOPEE01');

        $this->assertTrue($result['shipped']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/logistics/ship_order')) {
                return false;
            }

            return ($request['pickup']['address_id'] ?? null) === 123;
        });
    }

    public function test_cancel_order_sends_item_list_and_reason(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response($this->fakeOrderDetail(), 200),
            'partner.shopeemobile.com/api/v2/order/cancel_order*' => Http::response(['response' => []], 200),
        ]);

        $result = app(ShopeeOrderService::class)->cancelOrder('778899', '2606SHOPEE01', 'OUT_OF_STOCK');

        $this->assertTrue($result['cancelled']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/order/cancel_order')) {
                return false;
            }

            return ($request['cancel_reason'] ?? null) === 'OUT_OF_STOCK'
                && ($request['item_list'][0]['item_id'] ?? null) === 555100
                && ($request['item_list'][0]['model_id'] ?? null) === 12;
        });
    }

    public function test_cancel_reasons_returns_static_enum(): void
    {
        $reasons = app(ShopeeOrderService::class)->getCancelReasons();

        $ids = array_column($reasons, 'id');
        $this->assertContains('OUT_OF_STOCK', $ids);
        $this->assertContains('CUSTOMER_REQUEST', $ids);
    }

    public function test_logistics_returns_channel_list(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/logistics/get_channel_list*' => Http::response([
                'response' => ['logistics_channel_list' => [['logistics_channel_id' => 8001, 'logistics_channel_name' => 'JNE']]],
            ], 200),
        ]);

        $list = app(ShopeeOrderService::class)->getLogistics('778899');

        $this->assertCount(1, $list);
        $this->assertEquals('JNE', $list[0]['logistics_channel_name']);
    }
}
