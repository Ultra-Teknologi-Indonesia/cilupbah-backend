<?php

namespace Modules\Channel\Tests\Feature;

use Tests\TestCase;
use Modules\Channel\Models\ChannelShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ChannelDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
        ]);

        // mapper memetakan produk ke category_id = 1
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        ChannelShop::create([
            'shop_id' => 'SHOP-DL-1',
            'access_token' => 'access-token',
            'is_active' => true,
        ]);

        Http::fake([
            '*products/search*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'data' => [
                    'products' => [
                        [
                            'id' => 'TIKTOK-PROD-1',
                            'title' => 'Downloaded Phone',
                            'status' => 'ACTIVATE',
                            'skus' => [
                                [
                                    'id' => 'SKU-EXT-1',
                                    'seller_sku' => 'DL-SKU-1',
                                    'price' => ['tax_exclusive_price' => 10000],
                                    'inventory' => [['quantity' => 5]],
                                ],
                            ],
                            'main_images' => [['urls' => ['https://img/1.jpg']]],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_download_pulls_products_with_download_status()
    {
        $response = $this->postJson('/api/v1/tiktok/download', ['shop_id' => 'SHOP-DL-1']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.pulled_count', 1);

        $this->assertDatabaseHas('products', [
            'name' => 'Downloaded Phone',
            'status' => 'download',
        ]);

        $shop = ChannelShop::where('shop_id', 'SHOP-DL-1')->first();
        $this->assertDatabaseHas('product_channel_mappings', [
            'channel_shop_id' => $shop->id,
            'external_product_id' => 'TIKTOK-PROD-1',
            'sync_status' => 'synced',
        ]);
    }

    public function test_download_requires_shop_id()
    {
        $response = $this->postJson('/api/v1/tiktok/download', []);
        $response->assertStatus(422);
    }

    public function test_download_unsupported_channel_returns_422()
    {
        $response = $this->postJson('/api/v1/shopee/download', ['shop_id' => 'SHOP-DL-1']);
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_bulk_download_multiple_shops()
    {
        ChannelShop::create([
            'shop_id' => 'SHOP-DL-2',
            'access_token' => 'access-token-2',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/tiktok/download/bulk', [
            'shop_ids' => ['SHOP-DL-1', 'SHOP-DL-2'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.details');
    }
}
