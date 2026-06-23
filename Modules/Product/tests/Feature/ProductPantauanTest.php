<?php

namespace Modules\Product\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Modules\Product\Models\VariantOption;
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

    private function recompute(Product ...$products): void
    {
        $service = app(\Modules\Product\Services\ProductChannelValidationService::class);
        foreach ($products as $product) {
            $service->recompute($product);
        }
    }

    public function test_belum_upload_lists_products_not_uploaded_to_all_shops(): void
    {
        $partial = $this->product('Produk Sebagian'); 
        $this->mapTo($partial, $this->shopA, 'A-1');

        $full = $this->product('Produk Lengkap'); 
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
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mA->id, 'variant_id' => $v1->id, 'synced_price' => 150000]);

        $uniform = $this->product('Harga Sama');
        $v2 = ProductVariant::create(['product_id' => $uniform->id, 'sku' => 'HS-1', 'sell_price' => 100000, 'is_active' => true]);
        $mC = $this->mapTo($uniform, $this->shopA, 'HS-A');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $mC->id, 'variant_id' => $v2->id, 'synced_price' => 100000]);

        $this->recompute($divergent, $uniform);

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

        $this->recompute($divergent, $uniform);

        $response = $this->getJson('/api/v1/products/pantauan?lens=sku');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('SKU Beda', $names);
        $this->assertNotContains('SKU Sama', $names);
    }

    public function test_shopee_is_in_scope_and_flags_price_mismatch(): void
    {

        $divergent = $this->product('Shopee Harga Beda');
        $v = ProductVariant::create(['product_id' => $divergent->id, 'sku' => 'SHP-HB-1', 'sell_price' => 100000, 'is_active' => true]);
        $m = $this->mapTo($divergent, $this->shopB, 'SHP-HB');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $m->id, 'variant_id' => $v->id, 'synced_price' => 175000]);

        $this->recompute($divergent);

        $response = $this->getJson('/api/v1/products/pantauan?lens=harga&filter[channel]=shopee');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Shopee Harga Beda', $names);
    }

    public function test_sku_lens_ignores_mappings_without_channel_sku(): void
    {
        $product = $this->product('Tanpa Channel SKU');
        $v = ProductVariant::create(['product_id' => $product->id, 'sku' => 'X-1', 'sell_price' => 1, 'is_active' => true]);
        $m = $this->mapTo($product, $this->shopA, 'N-1');
        ProductVariantChannelMapping::create(['product_channel_mapping_id' => $m->id, 'variant_id' => $v->id]);

        $this->recompute($product);

        $response = $this->getJson('/api/v1/products/pantauan?lens=sku');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_invalid_lens_returns_422(): void
    {
        $this->getJson('/api/v1/products/pantauan?lens=ngawur')->assertStatus(422);
    }

    public function test_gagal_upload_lists_products_with_failed_upload_log(): void
    {
        $failed = $this->product('Upload Gagal');
        ProductSyncLog::create([
            'product_id' => $failed->id,
            'channel_shop_id' => $this->shopA->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => 'TikTok API Error: The sale attribute name or attribute ID should not be empty.',
        ]);

        $ok = $this->product('Upload Sukses');
        ProductSyncLog::create([
            'product_id' => $ok->id,
            'channel_shop_id' => $this->shopA->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
        ]);

        $response = $this->getJson('/api/v1/products/pantauan?lens=gagal_upload');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Upload Gagal', $names);
        $this->assertNotContains('Upload Sukses', $names);

        $row = collect($response->json('data'))->firstWhere('product_name', 'Upload Gagal');
        $this->assertStringContainsString('sale attribute', $row['last_upload_error']);
    }

    public function test_gagal_upload_flags_multivariant_without_options(): void
    {
        $attrId = DB::table('attributes')->insertGetId([
            'name' => 'Ukuran', 'type' => 'sales', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $noOpts = $this->product('Multi Tanpa Opsi');
        ProductVariant::create(['product_id' => $noOpts->id, 'sku' => 'MTO-1', 'sell_price' => 1, 'is_active' => true]);
        ProductVariant::create(['product_id' => $noOpts->id, 'sku' => 'MTO-2', 'sell_price' => 1, 'is_active' => true]);

        $withOpts = $this->product('Multi Dengan Opsi');
        $w1 = ProductVariant::create(['product_id' => $withOpts->id, 'sku' => 'MDO-1', 'sell_price' => 1, 'is_active' => true]);
        $w2 = ProductVariant::create(['product_id' => $withOpts->id, 'sku' => 'MDO-2', 'sell_price' => 1, 'is_active' => true]);
        VariantOption::create(['variant_id' => $w1->id, 'attribute_id' => $attrId, 'value' => '40']);
        VariantOption::create(['variant_id' => $w2->id, 'attribute_id' => $attrId, 'value' => '41']);

        $single = $this->product('Satu Varian');
        ProductVariant::create(['product_id' => $single->id, 'sku' => 'SV-1', 'sell_price' => 1, 'is_active' => true]);

        $response = $this->getJson('/api/v1/products/pantauan?lens=gagal_upload');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Multi Tanpa Opsi', $names);
        $this->assertNotContains('Multi Dengan Opsi', $names);
        $this->assertNotContains('Satu Varian', $names);
    }

    public function test_upsert_stores_canonical_channel_attributes(): void
    {
        $product = $this->product('Produk Atribut');

        app(\Modules\Channel\Repositories\ChannelProductRepository::class)
            ->upsertChannelMapping($product->id, 'SHOP-A', 'EXT-ATTR', 'synced', ['Warna' => 'Merah', 'Brand' => 'Acme']);

        $row = ProductChannelMapping::where('product_id', $product->id)->first();
        $this->assertNotNull($row);

        $this->assertSame(['Brand' => 'Acme', 'Warna' => 'Merah'], $row->channel_attributes);
    }

    public function test_atribut_lens_flags_multivariant_without_variation_attributes(): void
    {

        $attrId = DB::table('attributes')->insertGetId([
            'name' => 'Ukuran', 'type' => 'sales', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $bad = $this->product('Atribut Kurang');
        ProductVariant::create(['product_id' => $bad->id, 'sku' => 'AK-1', 'sell_price' => 1, 'is_active' => true]);
        ProductVariant::create(['product_id' => $bad->id, 'sku' => 'AK-2', 'sell_price' => 1, 'is_active' => true]);
        $this->mapTo($bad, $this->shopA, 'AK-A');

        $good = $this->product('Atribut Lengkap');
        $g1 = ProductVariant::create(['product_id' => $good->id, 'sku' => 'AL-1', 'sell_price' => 1, 'is_active' => true]);
        $g2 = ProductVariant::create(['product_id' => $good->id, 'sku' => 'AL-2', 'sell_price' => 1, 'is_active' => true]);
        VariantOption::create(['variant_id' => $g1->id, 'attribute_id' => $attrId, 'value' => '40']);
        VariantOption::create(['variant_id' => $g2->id, 'attribute_id' => $attrId, 'value' => '41']);
        $this->mapTo($good, $this->shopA, 'AL-A');

        $this->recompute($bad, $good);

        $response = $this->getJson('/api/v1/products/pantauan?lens=atribut');

        $response->assertStatus(200);
        $names = collect($response->json('data'))->pluck('product_name')->all();
        $this->assertContains('Atribut Kurang', $names);

        $this->assertNotContains('Atribut Lengkap', $names);

        $row = collect($response->json('data'))->firstWhere('product_name', 'Atribut Kurang');
        $codes = collect($row['mismatches'])->flatMap(fn ($m) => collect($m['attribute_issues'])->pluck('code'))->all();
        $this->assertContains('variation_attribute_missing', $codes);
    }
}
