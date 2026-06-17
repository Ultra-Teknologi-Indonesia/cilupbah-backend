<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ProductPantauanTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shopA;
    private ChannelShop $shopB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore([
            ['id' => 1, 'name' => 'Casing'],
            ['id' => 2, 'name' => 'Strap'],
        ]);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        $this->shopA = ChannelShop::create(['channel_id' => $tiktok->id, 'shop_id' => 'SHOP-A', 'shop_name' => 'Toko A', 'is_active' => true]);
        $this->shopB = ChannelShop::create(['channel_id' => $shopee->id, 'shop_id' => 'SHOP-B', 'shop_name' => 'Toko B', 'is_active' => true]);
    }

    private function product(string $name, array $attrs = []): Product
    {
        return Product::create(array_merge([
            'name' => $name,
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ], $attrs));
    }

    private function mapTo(Product $product, ChannelShop $shop, string $ext): ProductChannelMapping
    {
        return ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $shop->id,
            'external_product_id' => $ext,
            'sync_status' => 'synced',
        ]);
    }

    public function test_belum_upload_lists_products_not_uploaded_to_all_shops(): void
    {
        $partial = $this->product('Produk Sebagian'); // 2 toko aktif, baru 1
        $this->mapTo($partial, $this->shopA, 'A-1');

        $full = $this->product('Produk Lengkap'); // sudah di 2 toko
        $this->mapTo($full, $this->shopA, 'F-1');
        $this->mapTo($full, $this->shopB, 'F-2');

        $response = $this->getJson('/api/v1/products/pantauan?lens=belum_upload');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Produk Sebagian', $names);
        $this->assertNotContains('Produk Lengkap', $names);

        $row = collect($response->json('data'))->firstWhere('product_name', 'Produk Sebagian');
        $this->assertSame(1, $row['not_uploaded_count']);
    }

    public function test_harga_lists_price_divergent_products(): void
    {
        $divergent = $this->product('Harga Beda');
        $v1 = ProductVariant::create(['product_id' => $divergent->id, 'sku' => 'HB-1', 'sell_price' => 100000, 'is_active' => true]);
        $mA = $this->mapTo($divergent, $this->shopA, 'HB-A');
        $mB = $this->mapTo($divergent, $this->shopB, 'HB-B');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mA->id, 'variant_id' => $v1->id, 'override_price' => 100000]);
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mB->id, 'variant_id' => $v1->id, 'override_price' => 150000]);

        $uniform = $this->product('Harga Sama');
        $v2 = ProductVariant::create(['product_id' => $uniform->id, 'sku' => 'HS-1', 'sell_price' => 100000, 'is_active' => true]);
        $mC = $this->mapTo($uniform, $this->shopA, 'HS-A');
        $mD = $this->mapTo($uniform, $this->shopB, 'HS-B');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mC->id, 'variant_id' => $v2->id, 'override_price' => 100000]);
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mD->id, 'variant_id' => $v2->id, 'override_price' => 100000]);

        $response = $this->getJson('/api/v1/products/pantauan?lens=harga');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Harga Beda', $names);
        $this->assertNotContains('Harga Sama', $names);
    }

    public function test_filter_by_type_bundle(): void
    {
        $this->product('Produk Bundle', ['is_bundle' => true]);
        $this->product('Produk Satuan');

        $response = $this->getJson('/api/v1/products/pantauan?lens=belum_upload&filter[type]=bundle');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Produk Bundle', $names);
        $this->assertNotContains('Produk Satuan', $names);
    }

    public function test_search_matches_sku(): void
    {
        $this->product('Casing Polos', ['sku' => 'ZEBRA999']);
        $this->product('Produk Lain');

        $response = $this->getJson('/api/v1/products/pantauan?lens=belum_upload&search=ZEBRA999');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Casing Polos', $names);
        $this->assertNotContains('Produk Lain', $names);
    }

    public function test_upsert_stores_channel_seller_sku_and_price(): void
    {
        $product = $this->product('Produk Upsert');
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'MST-1', 'sell_price' => 50000, 'is_active' => true]);
        $mapping = $this->mapTo($product, $this->shopA, 'EXT-1');

        app(\Modules\Channel\Repositories\ChannelProductRepository::class)
            ->upsertVariantChannelMapping($mapping->id, $variant->id, 'CH-EXT-1', 'CHANNEL-SKU-X', 77000);

        $row = ProductVariantChannelMapping::where('variant_id', $variant->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('CHANNEL-SKU-X', $row->channel_seller_sku);
        $this->assertEquals(77000, (float) $row->synced_price);
        $this->assertSame('CH-EXT-1', $row->external_sku_id);
    }

    public function test_sku_lens_flags_divergent_channel_seller_sku(): void
    {
        $divergent = $this->product('SKU Beda');
        $v = ProductVariant::create(['product_id' => $divergent->id, 'sku' => 'MASTER-A', 'sell_price' => 1, 'is_active' => true]);
        $m = $this->mapTo($divergent, $this->shopA, 'D-1');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $m->id, 'variant_id' => $v->id, 'channel_seller_sku' => 'BERBEDA-A']);

        $uniform = $this->product('SKU Sama');
        $v2 = ProductVariant::create(['product_id' => $uniform->id, 'sku' => 'MASTER-B', 'sell_price' => 1, 'is_active' => true]);
        $m2 = $this->mapTo($uniform, $this->shopA, 'U-1');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $m2->id, 'variant_id' => $v2->id, 'channel_seller_sku' => 'MASTER-B']);

        $response = $this->getJson('/api/v1/products/pantauan?lens=sku');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('SKU Beda', $names);
        $this->assertNotContains('SKU Sama', $names);
    }

    public function test_sku_lens_ignores_mappings_without_channel_sku(): void
    {
        $product = $this->product('Tanpa Channel SKU');
        $v = ProductVariant::create(['product_id' => $product->id, 'sku' => 'X-1', 'sell_price' => 1, 'is_active' => true]);
        $m = $this->mapTo($product, $this->shopA, 'N-1');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $m->id, 'variant_id' => $v->id]);

        $response = $this->getJson('/api/v1/products/pantauan?lens=sku');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_invalid_lens_returns_422(): void
    {
        $this->getJson('/api/v1/products/pantauan?lens=ngawur')->assertStatus(422);
    }

    public function test_upsert_stores_canonical_channel_attributes(): void
    {
        $product = $this->product('Produk Atribut');

        app(\Modules\Channel\Repositories\ChannelProductRepository::class)
            ->upsertChannelMapping($product->id, 'SHOP-A', 'EXT-ATTR', 'synced', ['Warna' => 'Merah', 'Brand' => 'Acme']);

        $row = ProductChannelMapping::where('product_id', $product->id)->first();
        $this->assertNotNull($row);
        // Kanonik: key tersortir (Brand sebelum Warna).
        $this->assertSame(['Brand' => 'Acme', 'Warna' => 'Merah'], $row->channel_attributes);
    }

    public function test_atribut_lens_flags_divergent_attributes(): void
    {
        $divergent = $this->product('Atribut Beda');
        ProductChannelMapping::create(['product_id' => $divergent->id, 'channel_shop_id' => $this->shopA->id, 'external_product_id' => 'AB-A', 'sync_status' => 'synced', 'channel_attributes' => ['Brand' => 'Acme']]);
        ProductChannelMapping::create(['product_id' => $divergent->id, 'channel_shop_id' => $this->shopB->id, 'external_product_id' => 'AB-B', 'sync_status' => 'synced', 'channel_attributes' => ['Brand' => 'Beta']]);

        $uniform = $this->product('Atribut Sama');
        ProductChannelMapping::create(['product_id' => $uniform->id, 'channel_shop_id' => $this->shopA->id, 'external_product_id' => 'AS-A', 'sync_status' => 'synced', 'channel_attributes' => ['Brand' => 'Acme']]);
        ProductChannelMapping::create(['product_id' => $uniform->id, 'channel_shop_id' => $this->shopB->id, 'external_product_id' => 'AS-B', 'sync_status' => 'synced', 'channel_attributes' => ['Brand' => 'Acme']]);

        $response = $this->getJson('/api/v1/products/pantauan?lens=atribut');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Atribut Beda', $names);
        $this->assertNotContains('Atribut Sama', $names);
    }
}
