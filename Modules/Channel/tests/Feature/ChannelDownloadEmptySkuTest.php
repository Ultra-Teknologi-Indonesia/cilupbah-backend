<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\LazadaToInternalProductMapper;
use Modules\Channel\Services\ShopeeProductService;
use Modules\Channel\Services\ShopeeToInternalProductMapper;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Services\TikTokToInternalProductMapper;
use Modules\Product\Models\Category;
use Tests\TestCase;

class ChannelDownloadEmptySkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
            'services.lazada.app_key' => 'test_key',
            'services.lazada.app_secret' => 'test_secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'services.lazada.auth_url' => 'https://auth.lazada.com',
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
            'channel.lazada_defaults' => ['primary_category' => '10001234', 'brand' => 'No Brand'],
        ]);

        Category::create(['name' => 'Root', 'is_active' => true]);
    }

    private function makeShop(string $code, string $shopId): ChannelShop
    {
        $channel = Channel::firstOrCreate(['code' => $code], ['name' => ucfirst($code), 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => $shopId,
            'shop_name' => "Toko {$code}",
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'shop_cipher' => 'cipher',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);
    }

    public function test_lazada_mapper_returns_null_sku_when_seller_sku_empty(): void
    {
        $internal = app(LazadaToInternalProductMapper::class)->map([
            'item_id' => 555100,
            'primary_category' => 10001234,
            'status' => 'active',
            'attributes' => ['name' => 'Sepatu Tanpa SKU'],
            'images' => ['https://img.lazcdn.com/a.jpg'],
            'skus' => [[
                'SkuId' => 777100,
                'SellerSku' => '',
                'price' => 100000,
                'Status' => 'active',
            ]],
        ], 'LZ-100');

        $this->assertNull($internal['variants'][0]['sku']);
        $this->assertNull($internal['sku']);
        $this->assertStringNotContainsString('LZ-', (string) ($internal['variants'][0]['sku'] ?? ''));
    }

    public function test_shopee_mapper_returns_null_sku_when_model_sku_empty(): void
    {
        $internal = app(ShopeeToInternalProductMapper::class)->map([
            'item_id' => 555100,
            'item_name' => 'Kaos Tanpa SKU',
            'category_id' => 100182,
            'item_status' => 'NORMAL',
            'price_info' => [['current_price' => 50000]],
            'tier_variation' => [['name' => 'Warna', 'option_list' => [['option' => 'Merah']]]],
            'model_list' => [[
                'model_id' => 777100,
                'model_sku' => '',
                'tier_index' => [0],
                'price_info' => [['current_price' => 55000]],
            ]],
        ], 'SHOP-778899');

        $this->assertNull($internal['variants'][0]['sku']);
    }

    public function test_shopee_mapper_no_model_fallback_uses_item_sku_or_null(): void
    {
        $withItemSku = app(ShopeeToInternalProductMapper::class)->map([
            'item_id' => 555200,
            'item_name' => 'Topi Tanpa Variasi',
            'item_sku' => 'TOPI-REAL',
            'price_info' => [['current_price' => 30000]],
        ], 'SHOP-778899');
        $this->assertEquals('TOPI-REAL', $withItemSku['variants'][0]['sku']);
        $this->assertEquals('TOPI-REAL', $withItemSku['sku']);

        $withoutItemSku = app(ShopeeToInternalProductMapper::class)->map([
            'item_id' => 555300,
            'item_name' => 'Topi Tanpa Variasi Dan SKU',
            'item_sku' => '',
            'price_info' => [['current_price' => 30000]],
        ], 'SHOP-778899');
        $this->assertNull($withoutItemSku['variants'][0]['sku']);
        $this->assertNull($withoutItemSku['sku']);
    }

    public function test_tiktok_mapper_returns_null_sku_when_seller_sku_empty(): void
    {
        $internal = app(TikTokToInternalProductMapper::class)->map([
            'id' => 'TIKTOK-PROD-1',
            'title' => 'Casing Tanpa SKU',
            'status' => 'ACTIVATE',
            'skus' => [[
                'id' => 'SKU-EXT-1',
                'seller_sku' => '',
                'price' => ['tax_exclusive_price' => 99000],
            ]],
        ], 'SHOP-TT');

        $this->assertNull($internal['variants'][0]['sku']);
        $this->assertNull($internal['sku']);
    }

    public function test_lazada_download_mengabaikan_model_tanpa_sku(): void
    {
        $shop = $this->makeShop('lazada', 'LZ-EMPTY');

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'total_products' => 1,
                    'products' => [[
                        'item_id' => 555100,
                        'primary_category' => 10001234,
                        'status' => 'active',
                        'attributes' => ['name' => 'Sepatu Tanpa SKU'],
                        'images' => ['https://img.lazcdn.com/a.jpg'],
                        'skus' => [[
                            'SkuId' => 777100,
                            'SellerSku' => '',
                            'price' => 100000,
                            'Status' => 'active',
                        ]],
                    ]],
                ],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-EMPTY');
        $this->assertEquals(1, $count);

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', '555100')
            ->first();
        $this->assertNotNull($pcm);

        $this->assertSame(0, DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count(), 'Model tanpa SKU tidak boleh menghasilkan baris link');

        $this->assertDatabaseHas('product_sync_logs', [
            'channel_shop_id' => $shop->id,
            'action' => 'download',
            'status' => 'failed',
        ]);
    }

    public function test_shopee_download_mengabaikan_model_tanpa_sku(): void
    {
        $shop = $this->makeShop('shopee', 'SHP-EMPTY');

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_list*' => Http::response([
                'response' => [
                    'item' => [['item_id' => 555100, 'item_status' => 'NORMAL']],
                    'total_count' => 1,
                    'has_next_page' => false,
                    'next_offset' => 1,
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_item_base_info*' => Http::response([
                'response' => [
                    'item_list' => [[
                        'item_id' => 555100,
                        'item_name' => 'Kaos Tanpa SKU',
                        'category_id' => 100182,
                        'item_status' => 'NORMAL',
                        'has_model' => true,
                        'price_info' => [['current_price' => 50000]],
                    ]],
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_model_list*' => Http::response([
                'response' => [
                    'tier_variation' => [['name' => 'Warna', 'option_list' => [['option' => 'Merah']]]],
                    'model' => [[
                        'model_id' => 777100,
                        'model_sku' => '',
                        'tier_index' => [0],
                        'price_info' => [['current_price' => 55000]],
                    ]],
                ],
            ], 200),
        ]);

        $count = app(ShopeeProductService::class)->pullProducts('SHP-EMPTY');
        $this->assertEquals(1, $count);

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', '555100')
            ->first();
        $this->assertNotNull($pcm);

        $this->assertSame(0, DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count(), 'Model tanpa SKU tidak boleh menghasilkan baris link');
    }

    public function test_tiktok_download_mengabaikan_model_tanpa_sku(): void
    {
        $shop = $this->makeShop('tiktok', 'TT-EMPTY');

        $detail = [
            'id' => 'TIKTOK-PROD-EMPTY',
            'title' => 'Casing Tanpa SKU',
            'status' => 'ACTIVATE',
            'skus' => [[
                'id' => 'SKU-EXT-1',
                'seller_sku' => '',
                'price' => ['tax_exclusive_price' => 99000],
                'inventory' => [['quantity' => 5]],
            ]],
            'main_images' => [['urls' => ['https://img.tiktok/a.jpg']]],
        ];

        Http::fake([
            '*/products/TIKTOK-PROD-EMPTY*' => Http::response(['code' => 0, 'data' => $detail], 200),
            '*products/search*' => Http::response([
                'code' => 0,
                'data' => ['products' => [['id' => 'TIKTOK-PROD-EMPTY', 'title' => 'Casing Tanpa SKU']]],
            ], 200),
        ]);

        $count = app(TikTokProductService::class)->pullProducts('TT-EMPTY');
        $this->assertEquals(1, $count);

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $shop->id)
            ->where('external_product_id', 'TIKTOK-PROD-EMPTY')
            ->first();
        $this->assertNotNull($pcm);

        $this->assertSame(0, DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->count(), 'Model tanpa SKU tidak boleh menghasilkan baris link');
    }

    public function test_download_preserves_real_sku_when_mixed_with_empty(): void
    {
        $this->makeShop('lazada', 'LZ-MIXED');

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'total_products' => 1,
                    'products' => [[
                        'item_id' => 555400,
                        'primary_category' => 10001234,
                        'status' => 'active',
                        'attributes' => ['name' => 'Sepatu Campuran'],
                        'images' => ['https://img.lazcdn.com/a.jpg'],
                        'skus' => [
                            [
                                'SkuId' => 777401,
                                'SellerSku' => 'SKU-REAL-1',
                                'price' => 100000,
                                'Status' => 'active',
                            ],
                            [
                                'SkuId' => 777402,
                                'SellerSku' => '',
                                'price' => 120000,
                                'Status' => 'active',
                            ],
                        ],
                    ]],
                ],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-MIXED');
        $this->assertEquals(1, $count);

        $withSku = DB::table('product_variants')->where('sku', 'SKU-REAL-1')->first();
        $this->assertNotNull($withSku, 'Variant dengan SKU asli harus tetap tersimpan apa adanya');

        $this->assertSame(0, DB::table('product_variants')
            ->where('product_id', $withSku->product_id)
            ->whereNull('sku')
            ->count(), 'Variant tanpa SKU harus diabaikan, tidak ikut dibuat');
    }

    public function test_multiple_null_sku_variants_allowed(): void
    {
        $this->makeShop('lazada', 'LZ-MANY');

        $skus = [];
        for ($i = 1; $i <= 10; $i++) {
            $skus[] = [
                'SkuId' => 700000 + $i,
                'SellerSku' => '',
                'price' => 50000,
                'Status' => 'active',
            ];
        }

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'total_products' => 1,
                    'products' => [[
                        'item_id' => 555500,
                        'primary_category' => 10001234,
                        'status' => 'active',
                        'attributes' => ['name' => 'Produk 10 Variasi Tanpa SKU'],
                        'images' => ['https://img.lazcdn.com/a.jpg'],
                        'skus' => $skus,
                    ]],
                ],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-MANY');
        $this->assertEquals(1, $count);

        $product = DB::table('products')->where('name', 'Produk 10 Variasi Tanpa SKU')->first();
        $this->assertNotNull($product);

        $variants = DB::table('product_variants')->where('product_id', $product->id)->whereNull('sku')->get();
        $this->assertCount(1, $variants, 'Semua model tanpa SKU diabaikan; hanya satu varian penampung yang tersisa agar master tetap sah');

        $this->assertSame(0, DB::table('product_variant_channel_mappings')->count(), 'Tidak ada model tanpa SKU yang boleh tertaut');
    }
}
