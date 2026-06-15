<?php

namespace Tests\Feature\Channel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class ChannelShopManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function shop(array $overrides = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'shop_id' => 'shop-' . Str::random(8),
            'shop_name' => 'Toko Uji',
            'access_token' => 'tok',
            'refresh_token' => 'rtok',
            'token_expires_at' => now()->addDay(),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'order_sync_enabled' => true,
        ], $overrides));
    }

    private function api()
    {
        return $this->actingAs($this->user, 'sanctum');
    }

    public function test_list_excludes_disconnected_shops(): void
    {
        $active = $this->shop();
        $gone = $this->shop(['disconnected_at' => now()]);

        $res = $this->api()->getJson('/api/v1/marketplace/store');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($gone->id));
    }

    public function test_toggle_is_active(): void
    {
        $shop = $this->shop(['is_active' => true]);

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('channel_shops', ['id' => $shop->id, 'is_active' => false]);
    }

    public function test_toggle_order_sync(): void
    {
        $shop = $this->shop(['order_sync_enabled' => true]);

        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['order_sync_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.order_sync_enabled', false);
    }

    public function test_toggle_empty_payload_is_422(): void
    {
        $shop = $this->shop();
        $this->api()->patchJson("/api/v1/marketplace/store/{$shop->id}", [])->assertStatus(422);
    }

    public function test_toggle_non_boolean_is_422(): void
    {
        $shop = $this->shop();
        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['is_active' => 'yes'])
            ->assertStatus(422);
    }

    public function test_toggle_unknown_id_is_404_not_500(): void
    {
        $this->api()
            ->patchJson('/api/v1/marketplace/store/' . Str::uuid(), ['is_active' => true])
            ->assertStatus(404);
    }

    public function test_toggle_disconnected_shop_is_404(): void
    {
        $shop = $this->shop(['disconnected_at' => now()]);
        $this->api()
            ->patchJson("/api/v1/marketplace/store/{$shop->id}", ['is_active' => true])
            ->assertStatus(404);
    }

    public function test_disconnect_is_soft_and_removes_from_list(): void
    {
        $shop = $this->shop();

        $this->api()->deleteJson("/api/v1/marketplace/store/{$shop->id}")->assertOk();

        $fresh = $shop->fresh();
        $this->assertNotNull($fresh->disconnected_at);
        $this->assertNull($fresh->access_token); // token dihapus
        $this->assertNotNull($fresh); // baris tetap ada (tidak di-hard-delete)

        $ids = collect($this->api()->getJson('/api/v1/marketplace/store')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($shop->id));
    }

    public function test_disconnect_already_disconnected_is_404(): void
    {
        $shop = $this->shop(['disconnected_at' => now()]);
        $this->api()->deleteJson("/api/v1/marketplace/store/{$shop->id}")->assertStatus(404);
    }

    public function test_integration_status_error_when_refresh_token_expired(): void
    {
        $shop = $this->shop(['refresh_token_expires_at' => now()->subDay()]);

        $res = $this->api()->getJson('/api/v1/marketplace/store')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $shop->id);

        $this->assertSame('error', $row['integration']['status']);
    }
}
