<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Support\OAuthFlow;
use Tests\TestCase;

class OAuthFrontendRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const FE = 'https://app.ultra-fit.id';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.frontend_url' => self::FE,
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'services.lazada.redirect_uri' => 'https://staging.ultra-fit.id/api/v1/lazada/callback',
            'services.tiktok.app_key' => 'tt_key',
            'services.tiktok.app_secret' => 'tt_secret',
            'services.tiktok.redirect_uri' => 'https://staging.ultra-fit.id/api/v1/tiktok/callback',
        ]);

        Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
    }

    public function test_lazada_callback_redirects_to_frontend_on_success(): void
    {
        Http::fake([
            'auth.lazada.com/rest/auth/token/create*' => Http::response([
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
            ], 200),
        ]);

        $state = OAuthFlow::issueState('lazada');
        $response = $this->get("/api/v1/lazada/callback?code=auth-code-123&state={$state}");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString(self::FE . '/dashboard/integrasi-channel', $location);
        $this->assertStringContainsString('connected=lazada', $location);

        $this->assertDatabaseHas('channel_shops', ['shop_id' => '500600', 'is_active' => true]);
    }

    public function test_lazada_callback_redirects_to_frontend_with_error_on_invalid_state(): void
    {
        $response = $this->get('/api/v1/lazada/callback?code=x&state=salah');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/dashboard/integrasi-channel', $location);
        $this->assertStringContainsString('connected=lazada', $location);
        $this->assertStringContainsString('error=invalid_state', $location);

        $this->assertDatabaseCount('channel_shops', 0);
    }

    public function test_tiktok_callback_redirects_to_frontend_with_error_on_invalid_state(): void
    {
        $response = $this->get('/api/v1/tiktok/callback?code=x&state=salah');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/dashboard/integrasi-channel', $location);
        $this->assertStringContainsString('connected=tiktok', $location);
        $this->assertStringContainsString('error=invalid_state', $location);
    }

    public function test_callback_falls_back_to_json_when_frontend_url_unset(): void
    {
        config(['app.frontend_url' => null]);

        $this->getJson('/api/v1/lazada/callback?code=x&state=salah')
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }
}
