<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Helpers\ShopeeSignature;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Support\OAuthFlow;
use Tests\TestCase;

class ShopeeAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
            'services.shopee.redirect_uri' => 'https://staging.ultra-fit.id/api/v1/shopee/callback',
        ]);

        Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
    }

    private function fakeTokenResponse(array $override = []): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/token/get*' => Http::response(array_merge([
                'access_token' => 'shopee-access-token',
                'refresh_token' => 'shopee-refresh-token',
                'expire_in' => 14400,
            ], $override), 200),
        ]);
    }

    public function test_callback_exchanges_code_and_shop_id_and_saves_shop(): void
    {
        $this->fakeTokenResponse();

        $state = OAuthFlow::issueState('shopee');
        $response = $this->getJson("/api/v1/shopee/callback?code=auth-code-123&shop_id=778899&state={$state}");

        $response->assertStatus(200)
            ->assertJsonPath('data.new_shops.0.shop_id', '778899');

        $this->assertDatabaseHas('channel_shops', [
            'shop_id' => '778899',
            'access_token' => 'shopee-access-token',
            'refresh_token' => 'shopee-refresh-token',
            'is_active' => true,
        ]);

        $shop = ChannelShop::where('shop_id', '778899')->first();
        $this->assertEquals(Channel::where('code', 'shopee')->value('id'), $shop->channel_id);
        $this->assertNotNull($shop->token_expires_at);
    }

    public function test_callback_without_code_returns_400(): void
    {
        $state = OAuthFlow::issueState('shopee');
        $this->getJson("/api/v1/shopee/callback?shop_id=778899&state={$state}")
            ->assertStatus(400);
    }

    public function test_callback_without_shop_id_returns_400(): void
    {
        $state = OAuthFlow::issueState('shopee');
        $this->getJson("/api/v1/shopee/callback?code=auth-code-123&state={$state}")
            ->assertStatus(400);
    }

    public function test_callback_with_invalid_state_returns_422(): void
    {
        $this->getJson('/api/v1/shopee/callback?code=auth-code-123&shop_id=778899&state=invalid')
            ->assertStatus(422);

        $this->assertDatabaseCount('channel_shops', 0);
    }

    public function test_callback_with_error_response_returns_422_not_500(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/token/get*' => Http::response([
                'error' => 'invalid_code',
                'message' => 'authorization code invalid',
            ], 200),
        ]);

        $state = OAuthFlow::issueState('shopee');
        $this->getJson("/api/v1/shopee/callback?code=bad-code&shop_id=778899&state={$state}")
            ->assertStatus(422);

        $this->assertDatabaseCount('channel_shops', 0);
    }

    public function test_auth_returns_authorization_url(): void
    {
        $response = $this->getJson('/api/v1/shopee/auth');

        $response->assertStatus(200);
        $url = $response->json('data.auth_url');

        $this->assertStringContainsString('partner.shopeemobile.com/api/v2/shop/auth_partner', $url);
        $this->assertStringContainsString('partner_id=200123', $url);
        $this->assertStringContainsString('sign=', $url);
        $this->assertStringContainsString('redirect=', $url);
    }

    public function test_public_signature_is_deterministic_hmac_sha256_lowercase(): void
    {
        $expected = hash_hmac('sha256', '200123' . '/api/v2/auth/token/get' . '1700000000', 'test_partner_key');

        $sign = ShopeeSignature::publicSign('200123', '/api/v2/auth/token/get', 1700000000, 'test_partner_key');

        $this->assertEquals($expected, $sign);
        $this->assertEquals(strtolower($sign), $sign);
    }
}
