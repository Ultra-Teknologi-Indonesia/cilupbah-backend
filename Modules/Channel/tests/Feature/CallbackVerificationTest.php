<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\Channel;
use Tests\TestCase;

class CallbackVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => 'https://app.ultra-fit.id']);
        Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        Channel::create(['code' => 'tiktok', 'name' => 'TikTok']);
    }

    public function test_lazada_callback_probe_returns_200_not_redirect(): void
    {
        $res = $this->get('/api/v1/lazada/callback');

        $res->assertOk()->assertJsonPath('data.service', 'ready');
        $this->assertNull($res->headers->get('Location'));
    }

    public function test_tiktok_callback_probe_returns_200_not_redirect(): void
    {
        $res = $this->get('/api/v1/tiktok/callback');

        $res->assertOk()->assertJsonPath('data.service', 'ready');
        $this->assertNull($res->headers->get('Location'));
    }

    public function test_lazada_webhook_get_verify_returns_200(): void
    {
        $this->get('/api/v1/lazada/webhook')
            ->assertOk()
            ->assertJsonPath('data.service', 'ready');
    }

    public function test_real_oauth_with_bad_state_still_redirects(): void
    {

        $res = $this->get('/api/v1/lazada/callback?code=x&state=salah');

        $res->assertRedirect();
        $this->assertStringContainsString('error=invalid_state', $res->headers->get('Location'));
    }
}
