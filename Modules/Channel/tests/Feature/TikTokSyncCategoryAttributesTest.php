<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class TikTokSyncCategoryAttributesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $channelCategoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'services.tiktok.app_key' => 'k',
            'services.tiktok.app_secret' => 's',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
        ]);

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok']);

        ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'TK1',
            'shop_name' => 'Toko TikTok',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->channelCategoryId = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $this->channelCategoryId,
            'channel_id' => $channel->id,
            'external_id' => '601226',
            'parent_external_id' => '0',
            'name' => 'Handphone',
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fakeAttributes(): void
    {
        Http::fake([
            '*categories/601226/attributes*' => Http::response(['data' => ['attributes' => [
                [
                    'id' => '100001',
                    'name' => 'Brand',
                    'is_required' => true,
                    'is_multiple_selection' => false,
                    'type' => 'PRODUCT_PROPERTY',
                    'values' => [
                        ['id' => 'V1', 'name' => 'Samsung'],
                        ['id' => 'V2', 'name' => 'Apple'],
                    ],
                ],
                [
                    'id' => '100002',
                    'name' => 'Warna',
                    'is_required' => false,
                    'is_multiple_selection' => true,
                    'type' => 'SALES_PROPERTY',
                    'values' => [
                        ['id' => 'V3', 'name' => 'Hitam'],
                    ],
                ],
                [
                    'id' => '100003',
                    'name' => 'Garansi',
                    'is_required' => false,
                    'is_multiple_selection' => false,
                    'type' => 'PRODUCT_PROPERTY',
                    'values' => [],
                ],
            ]]], 200),
        ]);
    }

    public function test_sync_persists_attributes_and_options(): void
    {
        $this->fakeAttributes();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/category-attributes', ['shop_id' => 'TK1', 'category_id' => '601226']);

        $response->assertOk()
            ->assertJsonPath('data.synced', 3);

        $this->assertDatabaseHas('channel_attributes', [
            'channel_category_id' => $this->channelCategoryId,
            'external_id' => '100001',
            'name' => 'Brand',
            'is_required' => true,
            'is_sale_prop' => false,
        ]);

        $this->assertDatabaseHas('channel_attributes', [
            'external_id' => '100002',
            'name' => 'Warna',
            'is_multiple' => true,
            'is_sale_prop' => true,
        ]);

        $brand = DB::table('channel_attributes')->where('external_id', '100001')->first();
        $this->assertDatabaseHas('channel_attribute_options', [
            'channel_attribute_id' => $brand->id,
            'name' => 'Samsung',
        ]);
        $this->assertEquals(2, DB::table('channel_attribute_options')->where('channel_attribute_id', $brand->id)->count());
    }

    public function test_sync_is_idempotent(): void
    {
        $this->fakeAttributes();

        $payload = ['shop_id' => 'TK1', 'category_id' => '601226'];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/tiktok/sync/category-attributes', $payload)->assertOk();
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/tiktok/sync/category-attributes', $payload)->assertOk();

        $this->assertEquals(3, DB::table('channel_attributes')->where('channel_category_id', $this->channelCategoryId)->count());
        $brand = DB::table('channel_attributes')->where('external_id', '100001')->first();
        $this->assertEquals(2, DB::table('channel_attribute_options')->where('channel_attribute_id', $brand->id)->count());
    }

    public function test_unsynced_category_returns_422(): void
    {
        $this->fakeAttributes();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/tiktok/sync/category-attributes', ['shop_id' => 'TK1', 'category_id' => 'NOPE'])
            ->assertStatus(422);
    }
}
