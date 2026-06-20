<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\LazadaProductService;
use Modules\Channel\Services\TikTokProductService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class PullPreservesCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Category $correctCategory;
    private Category $wrongCategory;
    private ChannelShop $lazadaShop;
    private ChannelShop $tiktokShop;

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
            'channel.lazada_defaults' => ['primary_category' => '10001234', 'brand' => 'No Brand'],
        ]);

        $this->correctCategory = Category::create(['name' => 'Soft Case', 'is_active' => true]);
        $this->wrongCategory = Category::create(['name' => 'Belum Dikategorikan', 'is_active' => true]);

        $lazada = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        $this->lazadaShop = ChannelShop::create([
            'channel_id' => $lazada->id,
            'shop_id' => 'LZ-PRESERVE',
            'shop_name' => 'Toko Lazada',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addDays(7),
            'is_active' => true,
        ]);

        $tiktok = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->tiktokShop = ChannelShop::create([
            'channel_id' => $tiktok->id,
            'shop_id' => 'TT-PRESERVE',
            'shop_name' => 'Toko TikTok',
            'access_token' => 'access-token',
            'shop_cipher' => 'cipher',
            'is_active' => true,
        ]);
    }

    public function test_repull_lazada_preserves_existing_category(): void
    {
        $product = Product::create([
            'category_id' => $this->correctCategory->id,
            'name' => 'Existing Product',
            'description' => 'Already categorised correctly',
            'status' => 'master',
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SKU-PRESERVE-1',
            'sell_price' => 50000,
            'is_active' => true,
        ]);

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'total_products' => 1,
                    'products' => [[
                        'item_id' => 999001,
                        'primary_category' => 10001234,
                        'status' => 'active',
                        'attributes' => ['name' => 'Existing Product Updated', 'description' => 'Deskripsi'],
                        'images' => ['https://img.lazcdn.com/a.jpg'],
                        'skus' => [[
                            'SkuId' => 888001,
                            'SellerSku' => 'SKU-PRESERVE-1',
                            'price' => 60000,
                            'Status' => 'active',
                        ]],
                    ]],
                ],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-PRESERVE');

        $this->assertEquals(1, $count);

        $product->refresh();
        $this->assertEquals(
            $this->correctCategory->id,
            $product->category_id,
            'Category must not be overwritten on re-pull of known product'
        );
    }

    public function test_repull_tiktok_preserves_existing_category(): void
    {
        $product = Product::create([
            'category_id' => $this->correctCategory->id,
            'name' => 'TikTok Existing Product',
            'description' => 'Already categorised',
            'status' => 'master',
            'is_active' => true,
        ]);
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TK-PRESERVE-1',
            'sell_price' => 80000,
            'is_active' => true,
        ]);

        $detail = [
            'id' => 'TIKTOK-PRESERVE-1',
            'title' => 'TikTok Existing Product Updated',
            'status' => 'ACTIVATE',
            'description' => 'Deskripsi baru',
            'skus' => [[
                'id' => 'SKU-EXT-PRES',
                'seller_sku' => 'TK-PRESERVE-1',
                'price' => ['tax_exclusive_price' => 90000],
                'inventory' => [['quantity' => 10]],
            ]],
            'main_images' => [['urls' => ['https://img.tiktok/pres.jpg']]],
        ];

        Http::fake([
            '*/products/TIKTOK-PRESERVE-1*' => Http::response([
                'code' => 0, 'message' => 'Success', 'data' => $detail,
            ], 200),
            '*products/search*' => Http::response([
                'code' => 0, 'message' => 'Success',
                'data' => [
                    'products' => [['id' => 'TIKTOK-PRESERVE-1', 'title' => 'TikTok Existing Product Updated']],
                ],
            ], 200),
        ]);

        $count = app(TikTokProductService::class)->pullProducts('TT-PRESERVE');

        $this->assertEquals(1, $count);

        $product->refresh();
        $this->assertEquals(
            $this->correctCategory->id,
            $product->category_id,
            'Category must not be overwritten on re-pull of known TikTok product'
        );
    }

    public function test_first_pull_new_product_still_gets_category(): void
    {
        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => '0',
                'data' => [
                    'total_products' => 1,
                    'products' => [[
                        'item_id' => 999002,
                        'primary_category' => 10001234,
                        'status' => 'active',
                        'attributes' => ['name' => 'Brand New Product', 'description' => 'Fresh'],
                        'images' => ['https://img.lazcdn.com/new.jpg'],
                        'skus' => [[
                            'SkuId' => 888002,
                            'SellerSku' => 'SKU-BRAND-NEW',
                            'price' => 75000,
                            'Status' => 'active',
                        ]],
                    ]],
                ],
            ], 200),
        ]);

        $count = app(LazadaProductService::class)->pullProducts('LZ-PRESERVE');

        $this->assertEquals(1, $count);

        $variant = DB::table('product_variants')->where('sku', 'SKU-BRAND-NEW')->first();
        $this->assertNotNull($variant);

        $product = Product::find($variant->product_id);
        $this->assertNotNull($product->category_id, 'New products must still receive a category_id');
    }
}
