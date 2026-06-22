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
use Tests\TestCase;

class ProductUploadMatchTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore([['id' => 1, 'name' => 'Sepatu']]);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $tiktok->id, 'shop_id' => 'SHOP-A', 'shop_name' => 'Toko A', 'is_active' => true,
        ]);
    }

    private function product(string $name): Product
    {
        return Product::create([
            'name' => $name, 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true,
        ]);
    }

    private function match(Product $product): array
    {
        return $this->postJson("/api/v1/products/{$product->id}/upload-listing/match", [
            'store_ids' => [$this->shop->id],
        ])->json('data');
    }

    public function test_match_flags_multivariant_without_variation_attributes(): void
    {
        $product = $this->product('Adidas Samba OG');
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'ADSMB-40', 'sell_price' => 1800000, 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'ADSMB-41', 'sell_price' => 1800000, 'is_active' => true]);

        $rows = $this->match($product);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertFalse($row['matched']);
            $this->assertStringContainsString('atribut variasi', $row['message']);
        }
    }

    public function test_match_surfaces_failed_upload_log(): void
    {
        $product = $this->product('Produk Gagal');
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'PG-1', 'sell_price' => 1000, 'is_active' => true]);

        ProductSyncLog::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => 'TikTok API Error: boom',
        ]);

        $rows = $this->match($product);

        $this->assertNotEmpty($rows);
        $this->assertFalse($rows[0]['matched']);
        $this->assertStringContainsString('boom', $rows[0]['message']);
    }

    public function test_match_returns_matched_for_healthy_synced_product(): void
    {
        $product = $this->product('Produk Sehat');
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'PS-1', 'sell_price' => 1000, 'is_active' => true]);

        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id, 'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-1', 'sync_status' => 'synced',
        ]);
        ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $mapping->id, 'variant_id' => $variant->id, 'external_sku_id' => 'TT-1',
        ]);

        ProductSyncLog::create([
            'product_id' => $product->id, 'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD, 'status' => ProductSyncLog::STATUS_SUCCESS,
        ]);

        $rows = $this->match($product);

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertTrue($row['matched']);
            $this->assertSame('Sesuai sama master', $row['message']);
        }
    }
}
