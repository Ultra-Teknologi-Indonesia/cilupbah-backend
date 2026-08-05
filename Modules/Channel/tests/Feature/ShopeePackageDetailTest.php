<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeOrderService;
use Tests\TestCase;

class ShopeePackageDetailTest extends TestCase
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

    public function test_get_package_detail_uses_get_with_package_number_list(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'error' => '', 'message' => '',
                'response' => ['order_list' => [[
                    'order_sn' => '2606SHOPEE01',
                    'package_list' => [['package_number' => 'OFG-PKG-1']],
                ]]],
            ], 200),
            'partner.shopeemobile.com/api/v2/order/get_package_detail*' => Http::response([
                'error' => '', 'message' => '',
                'response' => ['package_list' => [[
                    'order_sn' => '2606SHOPEE01',
                    'package_number' => 'OFG-PKG-1',
                    'allow_self_design_awb' => true,
                    'fulfillment_status' => 'LOGISTICS_READY',
                ]]],
            ], 200),
        ]);

        $allow = app(ShopeeOrderService::class)
            ->checkAllowSelfDesignAwb((object) ['shop_id' => '778899'], '2606SHOPEE01');

        $this->assertTrue($allow);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v2/order/get_package_detail')) {
                return false;
            }

            return $request->method() === 'GET'
                && str_contains($request->url(), 'package_number_list=OFG-PKG-1')
                && ! str_contains($request->url(), 'package_list=');
        });
    }

    public function test_returns_false_and_skips_call_when_no_package_number(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/order/get_order_detail*' => Http::response([
                'error' => '', 'message' => '',
                'response' => ['order_list' => [['order_sn' => 'X', 'package_list' => []]]],
            ], 200),
        ]);

        $allow = app(ShopeeOrderService::class)
            ->checkAllowSelfDesignAwb((object) ['shop_id' => '778899'], 'X');

        $this->assertFalse($allow);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v2/order/get_package_detail'));
    }
}
