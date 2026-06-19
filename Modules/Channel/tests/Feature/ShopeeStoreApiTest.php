<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Tests\TestCase;

class ShopeeStoreApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->user = User::factory()->create();
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    public function test_show_returns_store_detail(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/shopee/stores/{$this->shop->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.shop_id', '778899')
            ->assertJsonPath('data.shop_name', 'Shopee 778899');
    }

    public function test_show_unknown_store_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/shopee/stores/' . \Ramsey\Uuid\Uuid::uuid7()->toString())
            ->assertStatus(404);
    }

    public function test_refresh_token_updates_access_token(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/auth/access_token/get*' => Http::response([
                'access_token' => 'fresh-token',
                'refresh_token' => 'fresh-refresh',
                'expire_in' => 14400,
            ], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/shopee/stores/{$this->shop->id}/refresh-token")
            ->assertStatus(200)
            ->assertJsonPath('data.shop_id', '778899');

        $this->assertEquals('fresh-token', $this->shop->fresh()->access_token);
    }
}
