<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

/**
 * Regresi manajemen toko TikTok: wajib auth, id UUID tidak 500,
 * dan tidak membocorkan access_token/refresh_token.
 */
class TikTokStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Channel $tiktok;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_key' => 'test_key',
            'services.tiktok.app_secret' => 'test_secret',
        ]);

        $this->user = User::factory()->create();
        $this->tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok Shop', 'is_active' => true]);
    }

    private function makeShop(array $override = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'channel_id' => $this->tiktok->id,
            'shop_id' => 'TT-' . fake()->unique()->numerify('######'),
            'shop_name' => 'Toko TikTok',
            'shop_cipher' => 'CIPHER-XYZ',
            'access_token' => 'SECRET-ACCESS-XYZ',
            'refresh_token' => 'SECRET-REFRESH-XYZ',
            'token_expires_at' => now()->addDays(7),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
        ], $override));
    }

    private function assertNoTokenLeak($response): void
    {
        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET-ACCESS-XYZ', $body);
        $this->assertStringNotContainsString('SECRET-REFRESH-XYZ', $body);
    }

    public function test_stores_require_auth(): void
    {
        $shop = $this->makeShop();

        $this->getJson('/api/v1/tiktok/stores')->assertStatus(401);
        $this->getJson("/api/v1/tiktok/stores/{$shop->id}")->assertStatus(401);
        $this->deleteJson("/api/v1/tiktok/stores/{$shop->id}")->assertStatus(401);
        $this->postJson("/api/v1/tiktok/stores/{$shop->id}/refresh-token")->assertStatus(401);
    }

    public function test_index_does_not_leak_tokens(): void
    {
        $shop = $this->makeShop();

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/tiktok/stores');

        $response->assertStatus(200)
            ->assertJsonPath('data.0.shop_id', $shop->shop_id)
            ->assertJsonPath('meta.per_page', 10);

        $this->assertNoTokenLeak($response);
    }

    public function test_show_with_valid_uuid_returns_detail_not_500(): void
    {
        $shop = $this->makeShop(['token_expires_at' => now()->subHour()]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/tiktok/stores/{$shop->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.shop_id', $shop->shop_id)
            ->assertJsonPath('data.token_status', 'expired');

        $this->assertNoTokenLeak($response);
    }

    public function test_show_non_uuid_id_returns_404_not_500(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/stores/not-a-uuid')
            ->assertStatus(404);
    }

    public function test_show_unknown_uuid_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/stores/' . fake()->uuid())
            ->assertStatus(404);
    }

    public function test_destroy_with_valid_uuid_disconnects_not_500(): void
    {
        $shop = $this->makeShop();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/tiktok/stores/{$shop->id}")
            ->assertStatus(200);

        $shop->refresh();
        $this->assertFalse($shop->is_active);
        $this->assertNull($shop->access_token);
        $this->assertNull($shop->refresh_token);
    }

    public function test_refresh_token_with_valid_uuid_updates_not_500(): void
    {
        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response([
                'code' => 0,
                'data' => [
                    'access_token' => 'NEW-ACCESS',
                    'refresh_token' => 'NEW-REFRESH',
                    'access_token_expire_in' => 25200,
                    'refresh_token_expire_in' => 2592000,
                ],
            ], 200),
        ]);

        $shop = $this->makeShop();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/tiktok/stores/{$shop->id}/refresh-token")
            ->assertStatus(200)
            ->assertJsonPath('data.shop_id', $shop->shop_id);

        $shop->refresh();
        $this->assertEquals('NEW-ACCESS', $shop->access_token);
        $this->assertEquals('NEW-REFRESH', $shop->refresh_token);
    }
}
