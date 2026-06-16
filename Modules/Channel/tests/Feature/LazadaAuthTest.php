<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaClient;
use Modules\Channel\Support\OAuthFlow;
use Tests\TestCase;

class LazadaAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'services.lazada.redirect_uri' => 'https://staging.ultra-fit.id/api/v1/lazada/callback',
        ]);

        Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
    }

    private function fakeTokenResponse(array $override = []): void
    {
        Http::fake([
            'auth.lazada.com/rest/auth/token/create*' => Http::response(array_merge([
                'access_token' => 'lazada-access-token',
                'refresh_token' => 'lazada-refresh-token',
                'expires_in' => 25200,
                'refresh_expires_in' => 2592000,
                'account' => 'seller@toko.com',
                'country' => 'id',
                'account_id' => '100200300',
                'country_user_info' => [
                    ['country' => 'id', 'user_id' => 'u-1', 'seller_id' => '500600', 'short_code' => 'SC123'],
                ],
                'code' => '0',
            ], $override), 200),
        ]);
    }

    public function test_callback_exchanges_code_and_saves_shop(): void
    {
        $this->fakeTokenResponse();

        $state = OAuthFlow::issueState('lazada');
        $response = $this->getJson("/api/v1/lazada/callback?code=auth-code-123&state={$state}");

        $response->assertStatus(200)
            ->assertJsonPath('data.new_shops.0.shop_id', '500600');

        $this->assertDatabaseHas('channel_shops', [
            'shop_id' => '500600',
            'access_token' => 'lazada-access-token',
            'refresh_token' => 'lazada-refresh-token',
            'is_active' => true,
        ]);

        $shop = ChannelShop::where('shop_id', '500600')->first();
        $this->assertEquals(Channel::where('code', 'lazada')->value('id'), $shop->channel_id);
        $this->assertNotNull($shop->token_expires_at);
    }

    public function test_callback_without_code_returns_400(): void
    {
        $state = OAuthFlow::issueState('lazada');
        $this->getJson("/api/v1/lazada/callback?state={$state}")
            ->assertStatus(400);
    }

    public function test_callback_with_error_response_returns_422_not_500(): void
    {

        Http::fake([
            'auth.lazada.com/rest/auth/token/create*' => Http::response([
                'type' => 'ISP',
                'code' => 'IllegalAccessToken',
                'message' => 'invalid authorization code',
            ], 200),
        ]);

        $state = OAuthFlow::issueState('lazada');
        $this->getJson("/api/v1/lazada/callback?code=bad-code&state={$state}")
            ->assertStatus(422);

        $this->assertDatabaseCount('channel_shops', 0);
    }

    public function test_auth_returns_authorization_url(): void
    {
        $response = $this->getJson('/api/v1/lazada/auth');

        $response->assertStatus(200);
        $url = $response->json('data.auth_url');

        $this->assertStringContainsString('auth.lazada.com/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test_key', $url);
        $this->assertStringContainsString('response_type=code', $url);

        $this->assertStringNotContainsString('force_auth', $url);
    }

    public function test_signature_is_deterministic_hmac_sha256(): void
    {
        $client = new LazadaClient();

        $params = ['app_key' => 'test_key', 'timestamp' => '123', 'sign_method' => 'sha256', 'code' => 'abc'];
        $expected = strtoupper(hash_hmac(
            'sha256',
            '/auth/token/create' . 'app_keytest_key' . 'codeabc' . 'sign_methodsha256' . 'timestamp123',
            'test_secret'
        ));

        $this->assertEquals($expected, $client->generateSign('/auth/token/create', $params));
    }
}
