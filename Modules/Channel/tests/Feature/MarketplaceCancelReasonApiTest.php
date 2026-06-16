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

    public function test_lazada_fetches_live_reasons_with_shop_id(): void
    {
        $this->mock(\Modules\Channel\Services\LazadaOrderService::class, function ($m) {
            $m->shouldReceive('getCancelReasons')->with('SHOP-1')->once()
                ->andReturn([['reason_id' => '101', 'name' => 'Out of stock (live)']]);
        });

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/lazada?shop_id=SHOP-1')
            ->assertStatus(200)
            ->assertJsonPath('meta.source', 'live')
            ->assertJsonFragment(['key' => '101', 'label' => 'Out of stock (live)']);
    }

    public function test_tiktok_fetches_live_reasons_with_shop_id(): void
    {
        $this->mock(\Modules\Channel\Services\TikTokOrderService::class, function ($m) {
            $m->shouldReceive('getCancelReasonsLive')->with('SHOP-2')->once()
                ->andReturn([['reason_key' => 'tt_live_key', 'reason' => 'Habis (live)']]);
        });

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/tiktok?shop_id=SHOP-2')
            ->assertStatus(200)
            ->assertJsonPath('meta.source', 'live')
            ->assertJsonFragment(['key' => 'tt_live_key', 'label' => 'Habis (live)']);
    }

    public function test_live_fetch_failure_falls_back_to_default_catalog(): void
    {
        $this->mock(\Modules\Channel\Services\TikTokOrderService::class, function ($m) {
            $m->shouldReceive('getCancelReasonsLive')->andThrow(new \RuntimeException('tiktok api down'));
        });

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/tiktok?shop_id=SHOP-3')
            ->assertStatus(200)
            ->assertJsonPath('meta.source', 'default')
            ->assertJsonFragment(['key' => 'seller_cancel_reason_out_of_stock']);
    }

    public function test_without_shop_id_returns_default_catalog(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/marketplace/cancel-reasons/lazada')
            ->assertStatus(200)
            ->assertJsonPath('meta.source', 'default')
            ->assertJsonFragment(['key' => 'out_of_stock']);
    }

    public function test_tiktok_live_parses_reject_reasons_payload(): void
    {
        $this->mock(\Modules\Channel\Repositories\ChannelShopRepository::class, function ($m) {
            $m->shouldReceive('findByShopId')->with('SHOP-9')
                ->andReturn((object) ['access_token' => 'tok', 'shop_cipher' => 'cipher']);
        });

        $this->mock(\Modules\Channel\Services\TikTokClient::class, function ($m) {
            $m->shouldReceive('request')
                ->with('GET', '/return_refund/202309/reject_reasons', \Mockery::any(), [], 'tok')
                ->andReturn([
                    'code' => 0,
                    'data' => [
                        'reasons' => [
                            ['name' => 'seller_reject_apply_you_have_reached_an_agreement_with_the_buyer', 'text' => 'You have reached an agreement with the buyer'],
                        ],
                    ],
                    'message' => 'Success',
                ]);
        });

        $service = app(\Modules\Channel\Services\TikTokOrderService::class);

        $this->assertSame([
            ['key' => 'seller_reject_apply_you_have_reached_an_agreement_with_the_buyer', 'label' => 'You have reached an agreement with the buyer'],
        ], $service->getCancelReasonsLive('SHOP-9'));
    }
}
