<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokClient;
use Tests\TestCase;

class ChannelHttp403TokenMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);
    }

    private function makeShopeeShop(): ChannelShop
    {
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'stale-access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ]);
    }

    public function test_shopee_403_error_auth_memicu_refresh_lalu_ulang(): void
    {
        $shop = $this->makeShopeeShop();

        Http::fake([
            'partner.shopeemobile.com/api/v2/logistics/get_channel_list*' => Http::sequence()
                ->push(['error' => 'error_auth', 'message' => 'Invalid access_token, please have a check.'], 403)
                ->push(['response' => ['logistics_channel_list' => [
                    ['logistics_channel_id' => 8001, 'logistics_channel_name' => 'JNE'],
                ]]], 200),
            'partner.shopeemobile.com/api/v2/auth/access_token/get*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expire_in' => 14400,
            ], 200),
        ]);

        $list = app(ShopeeOrderService::class)->getLogistics('778899');

        $this->assertCount(1, $list);
        $this->assertEquals('JNE', $list[0]['logistics_channel_name']);

        $shop->refresh();
        $this->assertEquals('new-access-token', $shop->access_token);
        $this->assertEquals('new-refresh-token', $shop->refresh_token);
    }

    public function test_shopee_error_http_tanpa_kode_error_tetap_runtime_exception(): void
    {
        $this->makeShopeeShop();

        Http::fake([
            'partner.shopeemobile.com/api/v2/logistics/get_channel_list*' => Http::response('bad gateway', 502),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Shopee API HTTP Error [502]');

        app(ShopeeOrderService::class)->getLogistics('778899');
    }

    public function test_tiktok_401_kode_token_dipetakan_jadi_token_expired(): void
    {
        Http::fake([
            'open-api.tiktokglobalshop.com/*' => Http::response([
                'code' => 40100,
                'message' => 'access token is invalid',
            ], 401),
        ]);

        $this->expectException(TokenExpiredException::class);

        (new TikTokClient())->request('GET', '/order/202309/orders', [], [], 'stale-token');
    }

    public function test_tiktok_error_http_tanpa_kode_tetap_runtime_exception(): void
    {
        Http::fake([
            'open-api.tiktokglobalshop.com/*' => Http::response('gateway timeout', 504),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TikTok API HTTP Error [504]');

        (new TikTokClient())->request('GET', '/order/202309/orders', [], [], 'stale-token');
    }

    public function test_lazada_403_kode_token_dipetakan_jadi_token_expired(): void
    {
        Http::fake([
            'api.lazada.co.id/*' => Http::response([
                'code' => 'IllegalAccessToken',
                'message' => 'access token is invalid',
            ], 403),
        ]);

        $this->expectException(TokenExpiredException::class);

        (new LazadaClient())->request('GET', '/seller/get', [], 'stale-token');
    }
}
