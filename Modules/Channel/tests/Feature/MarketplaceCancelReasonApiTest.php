<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceCancelReasonApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/marketplace/cancel-reasons')->assertStatus(401);
        $this->getJson('/api/v1/marketplace/cancel-reasons/tiktok')->assertStatus(401);
    }

    public function test_index_returns_reasons_for_all_marketplaces(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'tiktok' => [['key', 'label']],
                    'lazada' => [['key', 'label']],
                    'shopee' => [['key', 'label']],
                ],
            ]);
    }

    public function test_show_returns_platform_specific_reasons(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/shopee')
            ->assertStatus(200)
            ->assertJsonFragment(['key' => 'OUT_OF_STOCK', 'label' => 'Stok habis'])
            ->assertJsonFragment(['key' => 'COD_NOT_SUPPORTED', 'label' => 'COD tidak didukung']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/tiktok')
            ->assertStatus(200)
            ->assertJsonFragment(['key' => 'seller_cancel_reason_out_of_stock', 'label' => 'Stok habis']);
    }

    public function test_show_is_case_insensitive(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/Shopee')
            ->assertStatus(200)
            ->assertJsonFragment(['key' => 'OTHERS']);
    }

    public function test_unsupported_marketplace_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/bukalapak')
            ->assertStatus(422);
    }

    public function test_legacy_tiktok_endpoint_stays_backward_compatible(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/tiktok/cancel-reasons')
            ->assertStatus(200)
            ->assertJsonPath('data.seller_cancel_reason_out_of_stock', 'Stok habis')
            ->assertJsonPath('data.seller_cancel_reason_wrong_price', 'Kesalahan harga');
    }
}
