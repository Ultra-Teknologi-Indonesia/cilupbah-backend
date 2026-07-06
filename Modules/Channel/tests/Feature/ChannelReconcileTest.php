<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\ReconcileChannelDataJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\TikTokProductService;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductVariantChannelMapping;
use Tests\TestCase;

class ChannelReconcileTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $tiktokShop;
    private Product $product;
    private ProductChannelMapping $mapping;
    private ProductVariantChannelMapping $variantMapping;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
        ]);

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'SHOP-TT',
            'access_token' => 'access-token',
            'is_active' => true,
        ]);

        $this->product = Product::create(['name' => 'Produk Asli', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $this->product->id, 'sku' => 'MST-1', 'sell_price' => 50000, 'is_active' => true]);
        $this->mapping = ProductChannelMapping::create([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->tiktokShop->id,
            'external_product_id' => 'TIKTOK-PROD-1',
            'sync_status' => 'synced',
        ]);
        $this->variantMapping = ProductVariantChannelMapping::create([
            'product_channel_mapping_id' => $this->mapping->id,
            'variant_id' => $variant->id,
            'external_sku_id' => 'SKU-EXT-1',
            'channel_seller_sku' => 'MST-1',
        ]);

        Http::fake([
            '*products/search*' => Http::response([
                'code' => 0,
                'data' => [
                    'products' => [
                        [
                            'id' => 'TIKTOK-PROD-1',
                            'title' => 'NAMA DIUBAH DI CHANNEL',
                            'status' => 'ACTIVATE',
                            'product_attributes' => [
                                ['name' => 'Brand', 'values' => [['name' => 'Acme']]],
                            ],
                            'skus' => [
                                ['id' => 'SKU-EXT-1', 'seller_sku' => 'CHANNEL-DIFF', 'price' => ['tax_exclusive_price' => 99000]],
                            ],
                        ],
                        [
                            'id' => 'UNMAPPED-99',
                            'title' => 'Produk Channel Tak Termapping',
                            'status' => 'ACTIVATE',
                            'skus' => [['id' => 'X', 'seller_sku' => 'X', 'price' => ['tax_exclusive_price' => 1]]],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_reconcile_updates_channel_columns_without_touching_master(): void
    {
        app(TikTokProductService::class)->reconcileChannelData('SHOP-TT');

        $this->product->refresh();
        $this->assertSame('Produk Asli', $this->product->name);
        $this->assertSame(Product::STATUS_MASTER, $this->product->status);

        $this->mapping->refresh();
        $this->assertSame(['Brand' => 'Acme'], $this->mapping->channel_attributes);

        $this->variantMapping->refresh();
        $this->assertSame('CHANNEL-DIFF', $this->variantMapping->channel_seller_sku);
        $this->assertEquals(99000, (float) $this->variantMapping->synced_price);
    }

    public function test_reconcile_does_not_create_unmapped_products(): void
    {
        app(TikTokProductService::class)->reconcileChannelData('SHOP-TT');

        $this->assertSame(1, Product::count());
        $this->assertDatabaseMissing('products', ['name' => 'Produk Channel Tak Termapping']);
    }

    public function test_refresh_endpoint_queues_all_supported_channels_including_shopee(): void
    {
        Queue::fake();

        $lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        ChannelShop::create(['channel_id' => $lazada->id, 'shop_id' => 'SHOP-LZ', 'access_token' => 't', 'is_active' => true]);
        $shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        ChannelShop::create(['channel_id' => $shopee->id, 'shop_id' => 'SHOP-SP', 'access_token' => 't', 'is_active' => true]);

        $response = $this->postJson('/api/v1/channel-monitor/refresh');

        $response->assertStatus(202);
        // Shopee sekarang punya reconcileChannelData() sendiri (lihat ShopeeReconcileTest),
        // jadi tidak lagi masuk skipped_channels — semua 3 toko (tiktok+lazada+shopee) diqueue.
        $response->assertJsonPath('data.queued', 3);
        $this->assertEmpty($response->json('data.skipped_channels'));
        Queue::assertPushed(ReconcileChannelDataJob::class, 3);
    }
}
