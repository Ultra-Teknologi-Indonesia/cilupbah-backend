<?php

namespace Modules\Product\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductChannelListingsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $category = Category::create(['name' => 'HP']);
        $this->product = Product::create([
            'name' => 'iPhone', 'category_id' => $category->id,
            'status' => Product::STATUS_MASTER, 'is_active' => true, 'weight' => 0.3,
        ]);

        $blue = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'IP-BLUE', 'sell_price' => 1000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'IP-RED', 'sell_price' => 1000, 'is_active' => true]);

        // IP-BLUE ter-listing di Lazada (Toko Lazada).
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko Lazada', 'is_active' => true,
        ]);
        $pcm = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcm, 'product_id' => $this->product->id, 'channel_shop_id' => $shop->id,
            'external_product_id' => 'IT-1', 'sync_status' => 'synced',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => Uuid::uuid7()->toString(), 'product_channel_mapping_id' => $pcm,
            'variant_id' => $blue->id, 'external_sku_id' => 'SKU-1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function url(string $q = ''): string
    {
        return "/api/v1/products/{$this->product->id}/channel-listings" . ($q ? "?{$q}" : '');
    }

    public function test_listed_only_by_default(): void
    {
        $res = $this->getJson($this->url())->assertOk();

        $res->assertJsonCount(1, 'data')                       // hanya IP-BLUE (yang listing)
            ->assertJsonPath('data.0.sku', 'IP-BLUE')
            ->assertJsonCount(1, 'data.0.listings')
            ->assertJsonPath('data.0.listings.0.channel_code', 'lazada')
            ->assertJsonPath('data.0.listings.0.shop_name', 'Toko Lazada')
            ->assertJsonPath('data.0.listings.0.sync_status', 'synced')
            ->assertJsonPath('data.0.listings.0.external_product_id', 'IT-1');
    }

    public function test_include_unlisted_shows_all_variants(): void
    {
        $res = $this->getJson($this->url('include_unlisted=1'))->assertOk();

        $res->assertJsonCount(2, 'data');
        $red = collect($res->json('data'))->firstWhere('sku', 'IP-RED');
        $this->assertSame([], $red['listings']);
    }

    public function test_filter_by_channel_excludes_other_channels(): void
    {
        // Tidak ada listing TikTok → listed_only menyaring habis.
        $this->getJson($this->url('filter[channel]=tiktok'))->assertOk()->assertJsonCount(0, 'data');

        // Channel lazada → IP-BLUE muncul.
        $this->getJson($this->url('filter[channel]=lazada'))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.listings.0.channel_code', 'lazada');
    }

    public function test_unknown_product_returns_404(): void
    {
        $this->getJson('/api/v1/products/' . Uuid::uuid7()->toString() . '/channel-listings')
            ->assertStatus(404);
    }
}
