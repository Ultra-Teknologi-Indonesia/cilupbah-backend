<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class LazadaPushProductTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        config([
            'services.lazada.app_key' => 'k',
            'services.lazada.app_secret' => 's',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
        ]);
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $channelCategoryId = \Ramsey\Uuid\Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert([
            'id' => $channelCategoryId,
            'channel_id' => $channel->id,
            'external_id' => 'CAT-100',
            'parent_external_id' => '0',
            'name' => 'Cat Lazada',
            'is_leaf' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('category_channel_mappings')->insert([
            'category_id' => 1,
            'channel_category_id' => $channelCategoryId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProductAndShop(): array
    {
        $shop = ChannelShop::create([
            'channel_id' => Channel::where('code', 'lazada')->value('id'),
            'shop_id' => 'LZ1',
            'shop_name' => 'Toko',
            'access_token' => 'tok',
            'is_active' => true,
        ]);
        $product = Product::create(['name' => 'P', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true, 'weight' => 0.5]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'V1', 'sell_price' => 10000, 'is_active' => true]);
        ProductMedia::create(['product_id' => $product->id, 'media_type' => 'image', 'url' => 'https://x/img.jpg', 'is_primary' => true, 'sort_order' => 1]);

        return [$shop, $product];
    }

    public function test_push_success_marks_mapping_in_review(): void
    {
        [$shop, $product] = $this->makeProductAndShop();

        Http::fake([
            '*product/create*' => Http::response(['code' => '0', 'data' => ['item_id' => 'IT-99', 'sku_list' => [['SellerSku' => 'V1', 'SkuId' => 'S1']]]], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/products/push', ['shop_id' => 'LZ1', 'product_id' => $product->id])
            ->assertOk()
            ->assertJsonPath('data.external_product_id', 'IT-99');

        $this->assertDatabaseHas('product_channel_mappings', [
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'IT-99',
            'sync_status' => 'in_review',
        ]);
    }

    public function test_push_failure_returns_422_not_500(): void
    {
        [, $product] = $this->makeProductAndShop();

        Http::fake([
            '*product/create*' => Http::response(['code' => 'IllegalAccessToken', 'message' => 'invalid'], 200),
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/products/push', ['shop_id' => 'LZ1', 'product_id' => $product->id])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error');
    }

    public function test_push_unknown_shop_returns_404(): void
    {
        [, $product] = $this->makeProductAndShop();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/lazada/sync/products/push', ['shop_id' => 'NOPE', 'product_id' => $product->id])
            ->assertStatus(404);
    }
}
