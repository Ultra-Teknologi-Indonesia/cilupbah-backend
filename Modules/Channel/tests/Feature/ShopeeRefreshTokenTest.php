<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class ShopeeRefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function makeShop(array $override = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'token_expires_at' => now()->addMinutes(30),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $override));
    }

    private function fakeRefreshResponse(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/access_token/get*' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expire_in' => 14400,
            ], 200),
        ]);
    }

    public function test_near_expiry_token_is_refreshed(): void
    {
        $this->fakeRefreshResponse();
        $shop = $this->makeShop();

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $shop->refresh();
        $this->assertEquals('new-access-token', $shop->access_token);
        $this->assertEquals('new-refresh-token', $shop->refresh_token);
        $this->assertTrue($shop->token_expires_at->isFuture());
    }

    public function test_far_future_token_is_skipped(): void
    {
        $this->fakeRefreshResponse();
        $shop = $this->makeShop(['token_expires_at' => now()->addHours(10)]);

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $shop->refresh();
        $this->assertEquals('old-access-token', $shop->access_token);
        Http::assertNothingSent();
    }

    public function test_shop_with_expired_refresh_token_is_skipped(): void
    {
        $this->fakeRefreshResponse();
        $shop = $this->makeShop([
            'token_expires_at' => now()->addMinutes(10),
            'refresh_token_expires_at' => now()->subDay(),
        ]);

        $this->artisan('shopee:refresh-tokens')->assertSuccessful();

        $shop->refresh();
        $this->assertEquals('old-access-token', $shop->access_token);
        Http::assertNothingSent();
    }
}
