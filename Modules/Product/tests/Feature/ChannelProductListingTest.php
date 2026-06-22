<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Models\VariantOption;
use Tests\TestCase;

class ChannelProductListingTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $tiktokShop;
    private Attribute $warna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Casing']);

        $this->warna = Attribute::create(['name' => 'Warna', 'type' => 'sales']);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'SHOP-TT',
            'shop_name' => 'Cilupbah ID Mall',
            'is_active' => true,
        ]);
    }

    private function seedListing(
        ChannelShop $shop,
        string $name,
        string $externalId,
        ?string $channelUrl = null,
        string $syncStatus = 'synced',
        bool $withVariant = true,
    ): ProductChannelMapping {
        $product = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        ProductMedia::create([
            'product_id' => $product->id,
            'media_type' => 'image',
            'url' => 'https://cdn.example.com/listing.jpg',
            'is_primary' => true,
            'sort_order' => 1,
        ]);

        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $externalId,
            'channel_url' => $channelUrl,
            'sync_status' => $syncStatus,
        ]);

        if ($withVariant) {
            $variant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => "MASTER-{$externalId}",
                'sell_price' => 200000,
                'is_active' => true,
            ]);
            VariantOption::create(['variant_id' => $variant->id, 'attribute_id' => $this->warna->id, 'value' => 'Sky Blue']);

            ProductVariantChannelMapping::create([
                'product_channel_mapping_id' => $mapping->id,
                'variant_id' => $variant->id,
                'external_sku_id' => "CHANNEL-{$externalId}",
                'override_price' => 250000,
            ]);
        }

        return $mapping;
    }

    public function test_list_returns_listings_grouped_by_channel_with_sku_connections(): void
    {
        $this->seedListing($this->tiktokShop, 'Magnetic Case', 'TT-GROUP-1');

        $response = $this->getJson('/api/v1/products/channel-products');

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $response->assertJsonStructure([
            'data' => [[
                'channel_group_id',
                'store_id',
                'shop_id',
                'item_group_name',
                'min',
                'channel_id',
                'channel_code',
                'channel_url',
                'sync_status',
                'has_product_data',
                'product' => [[
                    'store_id',
                    'max_price',
                    'min_price',
                    'thumbnail',
                    'channel_id',
                    'master_sku',
                    'store_name',
                    'channel_sku',
                    'product_variation',
                ]],
            ]],
        ]);

        $item = $response->json('data.0');
        $this->assertSame('TT-GROUP-1', $item['channel_group_id']);
        $this->assertSame($this->tiktokShop->id, $item['store_id']);
        $this->assertSame('SHOP-TT', $item['shop_id']);
        $this->assertSame('Magnetic Case', $item['item_group_name']);
        $this->assertSame('Cilupbah ID Mall', $item['min']);
        $this->assertSame('tiktok', $item['channel_code']);
        $this->assertTrue($item['has_product_data']);

        $sku = $item['product'][0];
        $this->assertSame('MASTER-TT-GROUP-1', $sku['master_sku']);
        $this->assertSame('CHANNEL-TT-GROUP-1', $sku['channel_sku']);
        $this->assertSame('Sky Blue', $sku['product_variation']);
        $this->assertEquals(250000, $sku['max_price']);
    }

    public function test_default_pagination_is_ten(): void
    {
        $this->seedListing($this->tiktokShop, 'Case A', 'TT-A');

        $response = $this->getJson('/api/v1/products/channel-products');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 10);
    }

    public function test_has_product_data_false_when_no_variant_mappings(): void
    {
        $this->seedListing($this->tiktokShop, 'No SKU Listing', 'TT-EMPTY', withVariant: false);

        $response = $this->getJson('/api/v1/products/channel-products');

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('channel_group_id', 'TT-EMPTY');
        $this->assertFalse($item['has_product_data']);
        $this->assertSame([], $item['product']);
    }

    public function test_channel_url_built_when_empty(): void
    {
        $this->seedListing($this->tiktokShop, 'Built Url', 'TT-URL-1', channelUrl: null);

        $response = $this->getJson('/api/v1/products/channel-products');

        $item = collect($response->json('data'))->firstWhere('channel_group_id', 'TT-URL-1');
        $this->assertSame('https://shop.tiktok.com/view/product/TT-URL-1', $item['channel_url']);
    }

    public function test_uses_stored_channel_url(): void
    {
        $this->seedListing($this->tiktokShop, 'Stored Url', 'TT-URL-2', channelUrl: 'https://custom.url/x');

        $response = $this->getJson('/api/v1/products/channel-products');

        $item = collect($response->json('data'))->firstWhere('channel_group_id', 'TT-URL-2');
        $this->assertSame('https://custom.url/x', $item['channel_url']);
    }

    public function test_search_filters_by_product_name(): void
    {
        $this->seedListing($this->tiktokShop, 'Rhombic Softcase', 'TT-S-1');
        $this->seedListing($this->tiktokShop, 'Leather Wallet', 'TT-S-2');

        $response = $this->getJson('/api/v1/products/channel-products?search=Rhombic');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->all();
        $this->assertContains('Rhombic Softcase', $names);
        $this->assertNotContains('Leather Wallet', $names);
    }

    public function test_filter_by_channel_and_shop_id(): void
    {
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $shopeeShop = ChannelShop::create([
            'channel_id' => $shopee->id,
            'shop_id' => 'SHOP-SP',
            'shop_name' => 'Cilupbah Official',
            'is_active' => true,
        ]);

        $this->seedListing($this->tiktokShop, 'TikTok Listing', 'TT-F-1');
        $this->seedListing($shopeeShop, 'Shopee Listing', 'SP-F-1');

        $byChannel = $this->getJson('/api/v1/products/channel-products?filter[channel]=shopee');
        $byChannel->assertStatus(200);
        $names = collect($byChannel->json('data'))->pluck('item_group_name')->all();
        $this->assertSame(['Shopee Listing'], $names);

        $byShop = $this->getJson('/api/v1/products/channel-products?filter[shop_id]=SHOP-TT');
        $byShop->assertStatus(200);
        $shopNames = collect($byShop->json('data'))->pluck('item_group_name')->all();
        $this->assertSame(['TikTok Listing'], $shopNames);
    }

    public function test_filter_by_sync_status(): void
    {
        $this->seedListing($this->tiktokShop, 'Synced One', 'TT-OK', syncStatus: 'synced');
        $this->seedListing($this->tiktokShop, 'Failed One', 'TT-FAIL', syncStatus: 'failed');

        $response = $this->getJson('/api/v1/products/channel-products?filter[sync_status]=failed');

        $response->assertStatus(200);
        $statuses = collect($response->json('data'))->pluck('sync_status')->unique()->all();
        $this->assertSame(['failed'], $statuses);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $old = $this->seedListing($this->tiktokShop, 'Older', 'TT-OLD');
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $this->seedListing($this->tiktokShop, 'Newer', 'TT-NEW');

        $response = $this->getJson('/api/v1/products/channel-products');

        $response->assertStatus(200);
        $this->assertSame('Newer', $response->json('data.0.item_group_name'));
    }

    public function test_show_returns_single_listing(): void
    {
        $mapping = $this->seedListing($this->tiktokShop, 'Detail Listing', 'TT-DETAIL');

        $response = $this->getJson("/api/v1/products/channel-products/{$mapping->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.channel_group_id', 'TT-DETAIL');
        $response->assertJsonPath('data.item_group_name', 'Detail Listing');
        $response->assertJsonPath('data.product.0.master_sku', 'MASTER-TT-DETAIL');
    }

    public function test_show_unknown_returns_404(): void
    {
        $this->getJson('/api/v1/products/channel-products/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }

    public function test_search_matches_product_sku(): void
    {
        $product = Product::create([
            'name' => 'Casing Polos',
            'sku' => 'ZEBRA123',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->tiktokShop->id,
            'external_product_id' => 'TT-SKU',
            'sync_status' => 'synced',
        ]);
        $this->seedListing($this->tiktokShop, 'Produk Lain', 'TT-OTHER');

        $response = $this->getJson('/api/v1/products/channel-products?search=ZEBRA123');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->all();
        $this->assertContains('Casing Polos', $names);
        $this->assertNotContains('Produk Lain', $names);
    }

    private function seedPriced(string $name, string $ext, int $sellPrice, ?int $override): void
    {
        $product = Product::create([
            'name' => $name,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->tiktokShop->id,
            'external_product_id' => $ext,
            'sync_status' => 'synced',
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => "V-{$ext}",
            'sell_price' => $sellPrice,
            'is_active' => true,
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => "C-{$ext}",
            'override_price' => $override,
        ]);
    }

    public function test_filter_min_price_uses_effective_price(): void
    {
        $this->seedPriced('Murah', 'CHEAP', 50000, null);      
        $this->seedPriced('Mahal', 'PRICEY', 80000, 300000);   

        $response = $this->getJson('/api/v1/products/channel-products?filter[min_price]=100000');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->all();
        $this->assertContains('Mahal', $names);
        $this->assertNotContains('Murah', $names);
    }

    public function test_filter_max_price_uses_effective_price(): void
    {
        $this->seedPriced('Murah', 'CHEAP', 50000, null);
        $this->seedPriced('Mahal', 'PRICEY', 80000, 300000);

        $response = $this->getJson('/api/v1/products/channel-products?filter[max_price]=100000');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('item_group_name')->all();
        $this->assertContains('Murah', $names);
        $this->assertNotContains('Mahal', $names);
    }
}
