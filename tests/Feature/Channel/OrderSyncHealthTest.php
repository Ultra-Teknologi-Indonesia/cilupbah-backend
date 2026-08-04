<?php

namespace Tests\Feature\Channel;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Repositories\ChannelShopRepository;
use Tests\TestCase;

/**
 * Uji jalur berbasis DB: helper repo + eksposur Resource.
 * Matriks logika derive murni ada di OrderSyncStatusServiceTest (tanpa DB).
 */
class OrderSyncHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->createPrivilegedUser();
    }

    private function shop(array $overrides = []): ChannelShop
    {
        return ChannelShop::create(array_merge([
            'shop_id' => 'shop-' . Str::random(8),
            'shop_name' => 'Toko Uji',
            'access_token' => 'tok',
            'refresh_token' => 'rtok',
            'token_expires_at' => now()->addDays(7),
            'refresh_token_expires_at' => now()->addDays(30),
            'is_active' => true,
            'order_sync_enabled' => true,
        ], $overrides));
    }

    public function test_mark_order_sync_ok_stamps_and_clears_error(): void
    {
        $shop = $this->shop([
            'order_sync_status' => ChannelShop::ORDER_SYNC_PROBLEM,
            'last_order_error' => 'boom',
            'last_order_error_at' => now()->subMinutes(3),
        ]);

        app(ChannelShopRepository::class)->markOrderSyncOk($shop->id);

        $shop->refresh();
        $this->assertSame(ChannelShop::ORDER_SYNC_NORMAL, $shop->order_sync_status);
        $this->assertNotNull($shop->last_order_synced_at);
        $this->assertNull($shop->last_order_error);
        $this->assertNull($shop->last_order_error_at);
    }

    public function test_mark_order_sync_problem_records_error(): void
    {
        $shop = $this->shop();

        app(ChannelShopRepository::class)->markOrderSyncProblem($shop->id, 'gagal tarik pesanan');

        $shop->refresh();
        $this->assertSame(ChannelShop::ORDER_SYNC_PROBLEM, $shop->order_sync_status);
        $this->assertSame('gagal tarik pesanan', $shop->last_order_error);
        $this->assertNotNull($shop->last_order_error_at);
    }

    public function test_store_endpoint_exposes_order_sync(): void
    {
        $shop = $this->shop(['last_order_synced_at' => now()->subMinutes(4)]);

        $res = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/store')
            ->assertOk();

        $row = collect($res->json('data'))->firstWhere('id', $shop->id);
        $this->assertNotNull($row);
        $this->assertSame(ChannelShop::ORDER_SYNC_NORMAL, $row['order_sync']['status']);
        $this->assertArrayHasKey('last_order_synced_at', $row);
    }
}
