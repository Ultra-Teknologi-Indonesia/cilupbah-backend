<?php

namespace Tests\Feature\Channel;

use Modules\Channel\Services\TikTokAuthService;
use Modules\Channel\Support\OAuthFlow;
use Tests\TestCase;

class OAuthCallbackTest extends TestCase
{
    public function test_auth_endpoint_returns_url_with_state(): void
    {
        $res = $this->getJson('/api/v1/tiktok/auth');

        $res->assertOk();
        $url = $res->json('data.auth_url');
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('state=', $url);
    }

    public function test_callback_invalid_state_redirects_to_frontend_with_error(): void
    {
        config(['app.frontend_url' => 'https://cilupbah-fe.vercel.app']);

        $res = $this->get('/api/v1/tiktok/callback?state=bogus&code=abc');

        $res->assertRedirect();
        $this->assertStringContainsString('connected=tiktok', $res->headers->get('Location'));
        $this->assertStringContainsString('error=invalid_state', $res->headers->get('Location'));
    }

    public function test_callback_invalid_state_returns_422_json_when_no_frontend_url(): void
    {
        config(['app.frontend_url' => null]);

        $res = $this->get('/api/v1/tiktok/callback?state=bogus&code=abc');

        $res->assertStatus(422);
        $res->assertJson(['status' => 'error']);
    }

    public function test_callback_missing_code_is_not_500(): void
    {
        config(['app.frontend_url' => null]);
        $state = OAuthFlow::issueState('tiktok');

        $res = $this->get('/api/v1/tiktok/callback?state=' . $state);

        $res->assertStatus(400);
        $res->assertJson(['status' => 'error']);
    }

    public function test_callback_success_redirects_with_count(): void
    {
        config(['app.frontend_url' => 'https://cilupbah-fe.vercel.app']);

        $this->mock(TikTokAuthService::class, function ($mock) {
            $mock->shouldReceive('handleCallback')
                ->once()
                ->andReturn([['shop_name' => 'Toko A'], ['shop_name' => 'Toko B']]);
        });

        $state = OAuthFlow::issueState('tiktok');
        $res = $this->get('/api/v1/tiktok/callback?state=' . $state . '&code=valid');

        $res->assertRedirect();
        $this->assertStringContainsString('connected=tiktok', $res->headers->get('Location'));
        $this->assertStringContainsString('count=2', $res->headers->get('Location'));
    }

    public function test_callback_service_failure_maps_to_error_not_500(): void
    {
        config(['app.frontend_url' => null]);

        $this->mock(TikTokAuthService::class, function ($mock) {
            $mock->shouldReceive('handleCallback')
                ->once()
                ->andThrow(new \RuntimeException('marketplace down'));
        });

        $state = OAuthFlow::issueState('tiktok');
        $res = $this->get('/api/v1/tiktok/callback?state=' . $state . '&code=valid');

        $res->assertStatus(422);
        $res->assertJson(['status' => 'error']);
    }
}
