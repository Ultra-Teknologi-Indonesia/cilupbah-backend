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
use Modules\Product\Models\ProductWholesalePrice;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class ProductChannelPricesPriceBookTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ProductVariant $blue;
    private ProductVariant $red;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $category = Category::create(['name' => 'HP']);
        $this->product = Product::create([
            'name' => 'iPhone', 'category_id' => $category->id,
            'status' => Product::STATUS_MASTER, 'is_active' => true, 'weight' => 0.3,
        ]);
        $this->blue = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'IP-BLUE', 'sell_price' => 10000, 'is_active' => true]);
        $this->red = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'IP-RED', 'sell_price' => 11000, 'is_active' => true]);

        // Listing Lazada: BLUE override 12000, RED hanya synced_price 9000.
        $channel = Channel::create(['code' => 'lazada', 'name' => 'Lazada']);
        $shop = ChannelShop::create(['channel_id' => $channel->id, 'shop_id' => 'LZ1', 'shop_name' => 'Toko Lazada', 'is_active' => true]);
        $pcm = Uuid::uuid7()->toString();
        DB::table('product_channel_mappings')->insert([
            'id' => $pcm, 'product_id' => $this->product->id, 'channel_shop_id' => $shop->id,
            'external_product_id' => 'IT-1', 'sync_status' => 'synced', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            ['id' => Uuid::uuid7()->toString(), 'product_channel_mapping_id' => $pcm, 'variant_id' => $this->blue->id,
                'external_sku_id' => 'S1', 'override_price' => 12000, 'synced_price' => 11500, 'created_at' => now(), 'updated_at' => now()],
            ['id' => Uuid::uuid7()->toString(), 'product_channel_mapping_id' => $pcm, 'variant_id' => $this->red->id,
                'external_sku_id' => 'S2', 'override_price' => null, 'synced_price' => 9000, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ── #5 Harga Channel ───────────────────────────────────────────────
    public function test_channel_prices_uses_override_then_synced(): void
    {
        $res = $this->getJson("/api/v1/products/{$this->product->id}/channel-prices")->assertOk();
        $rows = collect($res->json('data'));

        $blue = $rows->firstWhere('sku', 'IP-BLUE');
        $this->assertEquals(10000, $blue['internal_price']);
        $this->assertEquals(12000, $blue['prices'][0]['price']);          // override
        $this->assertSame('lazada', $blue['prices'][0]['channel_code']);

        $red = $rows->firstWhere('sku', 'IP-RED');
        $this->assertEquals(9000, $red['prices'][0]['price']);            // fallback synced_price
    }

    public function test_channel_prices_filter_channel(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}/channel-prices?filter[channel]=tiktok")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_channel_prices_404(): void
    {
        $this->getJson('/api/v1/products/' . Uuid::uuid7()->toString() . '/channel-prices')->assertStatus(404);
    }

    // ── #6 Buku Harga ──────────────────────────────────────────────────
    public function test_price_book_lists_wholesale_tiers(): void
    {
        ProductWholesalePrice::create(['variant_id' => $this->blue->id, 'customer_type' => 'reseller', 'min_qty' => 10, 'max_qty' => 49, 'price' => 9500]);
        ProductWholesalePrice::create(['variant_id' => $this->blue->id, 'customer_type' => 'reseller', 'min_qty' => 50, 'max_qty' => null, 'price' => 9000]);

        $res = $this->getJson("/api/v1/products/{$this->product->id}/price-book")->assertOk();

        $res->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'variant_id', 'sku', 'customer_type', 'min_qty', 'max_qty', 'price']]])
            ->assertJsonPath('data.0.sku', 'IP-BLUE')
            ->assertJsonPath('data.0.min_qty', 10);   // urut min_qty asc
    }

    public function test_price_book_empty_when_no_wholesale(): void
    {
        $this->getJson("/api/v1/products/{$this->product->id}/price-book")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_price_book_404(): void
    {
        $this->getJson('/api/v1/products/' . Uuid::uuid7()->toString() . '/price-book')->assertStatus(404);
    }
}
