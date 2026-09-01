<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\Category;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use App\Models\User;
use Tests\TestCase;

class UnifiedChannelSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected ChannelShop $shopeeShop;
    protected ChannelShop $tiktokShop;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tiktok.app_key' => 'test-key',
            'services.tiktok.app_secret' => 'test-secret',
            'services.tiktok.base_url' => 'https://open-api.tiktokglobalshop.com',
        ]);

        $this->user = $this->createPrivilegedUser();

        $shopeeChannel = Channel::firstOrCreate(['code' => 'shopee'], ['name' => 'Shopee']);
        $tiktokChannel = Channel::firstOrCreate(['code' => 'tiktok'], ['name' => 'TikTok Shop']);

        $this->shopeeShop = ChannelShop::create([
            'channel_id'    => $shopeeChannel->id,
            'shop_id'       => 'SP-SHOP-1',
            'shop_name'     => 'Shopee Official Store',
            'access_token'  => 'fake-shopee-token',
            'is_active'     => true,
        ]);

        $this->tiktokShop = ChannelShop::create([
            'channel_id'    => $tiktokChannel->id,
            'shop_id'       => 'TT-SHOP-1',
            'shop_name'     => 'TikTok Official Store',
            'access_token'  => 'fake-tiktok-token',
            'is_active'     => true,
        ]);
    }

    public function test_unified_search_aggregates_multiple_stores_in_single_call(): void
    {

        Http::fake([
            '*/api/v2/product/get_item_list*' => Http::response([
                'error' => '',
                'response' => [
                    'item' => [
                        ['item_id' => 101, 'item_status' => 'NORMAL'],
                    ],
                    'has_next_page' => false,
                    'next_offset' => 0,
                    'total_count' => 1,
                ],
            ], 200),
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'error' => '',
                'response' => [
                    'item_list' => [
                        [
                            'item_id' => 101,
                            'item_name' => 'Shopee Case Pro',
                            'item_sku' => 'SKU-CASE-1',
                            'image' => ['image_url_list' => ['https://img.shopee/1.jpg']],
                        ],
                    ],
                ],
            ], 200),
            '*/api/v2/product/get_model_list*' => Http::response([
                'error' => '',
                'response' => ['model' => []],
            ], 200),
            '*/product/202309/products/search*' => Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'products' => [
                        [
                            'id' => 'tt-prod-202',
                            'title' => 'TikTok Case Pro',
                            'skus' => [
                                ['id' => 'tt-sku-1', 'seller_sku' => 'SKU-CASE-1'],
                            ],
                            'main_images' => [['url_list' => ['https://img.tiktok/2.jpg']]],
                            'status' => 'ACTIVATE',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $category = \Modules\Product\Models\Category::create([
            'name' => 'General Category',
            'is_active' => true,
        ]);

        $masterProduct = Product::create([
            'category_id' => $category->id,
            'name' => 'Master Case Pro',
            'sku' => 'SKU-CASE-1',
            'status' => 'master',
            'is_active' => true,
        ]);

        ProductChannelMapping::create([
            'product_id' => $masterProduct->id,
            'channel_shop_id' => $this->shopeeShop->id,
            'external_product_id' => '101',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'SKU-CASE-1',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $items = $response->json('data');
        $this->assertCount(2, $items);

        $shopeeItem = collect($items)->firstWhere('channel_code', 'shopee');
        $this->assertNotNull($shopeeItem);
        $this->assertSame('101', (string) $shopeeItem['external_product_id']);
        $this->assertTrue($shopeeItem['already_downloaded']);
        $this->assertSame('none', $shopeeItem['download_action']);
        $this->assertSame($masterProduct->id, $shopeeItem['master_product_id']);

        $tiktokItem = collect($items)->firstWhere('channel_code', 'tiktok');
        $this->assertNotNull($tiktokItem);
        $this->assertSame('tt-prod-202', (string) $tiktokItem['external_product_id']);
        $this->assertFalse($tiktokItem['already_downloaded']);
        $this->assertSame('download', $tiktokItem['download_action']);
    }

    public function test_unified_search_can_filter_by_specific_shop_ids(): void
    {
        Http::fake([
            '*/product/202309/products/search*' => Http::response([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'products' => [
                        [
                            'id' => 'tt-prod-202',
                            'title' => 'TikTok Case Pro',
                            'skus' => [
                                ['id' => 'tt-sku-1', 'seller_sku' => 'SKU-CASE-1'],
                            ],
                            'main_images' => [['url_list' => ['https://img.tiktok/2.jpg']]],
                            'status' => 'ACTIVATE',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'SKU-CASE-1',
                'shop_ids' => ['TT-SHOP-1'],
            ]);

        $response->assertStatus(200);
        $items = $response->json('data');
        $this->assertCount(1, $items);
        $this->assertSame('tiktok', $items[0]['channel_code']);
        $this->assertSame('TT-SHOP-1', $items[0]['shop_id']);
    }

    public function test_unified_search_uses_marketplace_as_source_and_enriches_existing_mapping(): void
    {
        Http::fake([
            '*/api/v2/product/get_item_list*' => Http::response([
                'error' => '',
                'response' => [
                    'item' => [['item_id' => 311, 'item_status' => 'NORMAL']],
                    'has_next_page' => false,
                    'next_offset' => 0,
                ],
            ], 200),
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'error' => '',
                'response' => [
                    'item_list' => [[
                        'item_id' => 311,
                        'item_name' => 'Marketplace Case',
                        'item_sku' => 'LOCAL-SKU-11',
                        'image' => ['image_url_list' => ['https://img.shopee/local.jpg']],
                    ]],
                ],
            ], 200),
        ]);

        $category = Category::create(['name' => 'General', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Master Case',
            'sku' => 'LOCAL-SKU-11',
            'status' => 'master',
            'is_active' => true,
        ]);

        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shopeeShop->id,
            'external_product_id' => '311',
            'sync_status' => 'synced',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'LOCAL-SKU-11',
                'shop_ids' => ['SP-SHOP-1'],
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.0.external_product_id', '311')
            ->assertJsonPath('data.0.already_downloaded', true)
            ->assertJsonPath('data.0.master_product_id', $product->id);

        Http::assertSentCount(2);
    }

    public function test_unified_search_does_not_return_local_only_variant_mapping(): void
    {
        Http::fake([
            '*/api/v2/product/get_item_list*' => Http::response([
                'error' => '',
                'response' => [
                    'item' => [['item_id' => 312, 'item_status' => 'NORMAL']],
                    'has_next_page' => false,
                    'next_offset' => 0,
                ],
            ], 200),
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'error' => '',
                'response' => [
                    'item_list' => [[
                        'item_id' => 312,
                        'item_name' => 'Marketplace Variant Case',
                        'item_sku' => 'SHP-BLACK-11',
                        'has_model' => true,
                    ]],
                ],
            ], 200),
            '*/api/v2/product/get_model_list*' => Http::response([
                'error' => '',
                'response' => [
                    'model' => [['model_sku' => 'SHP-BLACK-11']],
                    'tier_variation' => [],
                ],
            ], 200),
        ]);

        $category = Category::create(['name' => 'Variant Category', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Master Variant Case',
            'sku' => 'MASTER-CASE',
            'status' => 'master',
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VARIANT-BLUE-11',
            'sell_price' => 1000,
            'is_active' => true,
        ]);
        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shopeeShop->id,
            'external_product_id' => 'shopee-listing-variant',
            'sync_status' => 'synced',
        ]);
        DB::table('product_variant_channel_mappings')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'product_channel_mapping_id' => $mapping->id,
            'variant_id' => $variant->id,
            'channel_seller_sku' => 'SHP-BLUE-11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'VARIANT-BLUE-11',
                'shop_ids' => ['SP-SHOP-1'],
            ]);

        $response->assertOk()->assertJsonPath('data', []);

        Http::assertSentCount(3);
    }

    public function test_unified_search_isolates_remote_store_failure(): void
    {
        Http::fake([
            '*product/202309/products/search*' => Http::response([
                'code' => 500,
                'message' => 'temporary upstream failure',
            ], 500),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'NOT-IN-CATALOG',
                'shop_ids' => ['TT-SHOP-1'],
            ]);

        $response->assertOk()
            ->assertJsonPath('meta.total_stores', 1)
            ->assertJsonPath('meta.failed_stores.0.shop_id', 'TT-SHOP-1');
    }

    public function test_unified_search_redacts_provider_credentials_from_failed_store(): void
    {
        config([
            'services.lazada.app_key' => 'test-lazada-key',
            'services.lazada.app_secret' => 'test-lazada-secret',
            'services.lazada.base_url' => 'https://api.lazada.co.id/rest',
            'channel.search_remote_attempts' => 1,
        ]);

        $lazadaChannel = Channel::create(['code' => 'lazada', 'name' => 'Lazada', 'is_active' => true]);
        ChannelShop::create([
            'channel_id' => $lazadaChannel->id,
            'shop_id' => 'LZ-SECRET-1',
            'shop_name' => 'Lazada Store',
            'access_token' => 'secret-token',
            'is_active' => true,
        ]);

        Http::fake([
            'api.lazada.co.id/rest/products/get*' => Http::response([
                'code' => 'InvalidParameter',
                'message' => 'provider failure access_token=secret-token sign=secret-sign',
            ], 500),
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/channel/download/search', [
                'q' => 'SKU-SECRET-1',
                'shop_ids' => ['LZ-SECRET-1'],
            ]);

        $response->assertOk()
            ->assertJsonPath('meta.failed_stores.0.error_code', 'UPSTREAM_ERROR');

        $body = $response->getContent();
        $this->assertStringNotContainsString('secret-token', $body);
        $this->assertStringNotContainsString('secret-sign', $body);
        $this->assertStringNotContainsString('access_token=', $body);
    }

    public function test_shopee_remote_search_matches_a_non_first_variant_sku(): void
    {
        Http::fake([
            '*/api/v2/product/get_item_list*' => Http::response([
                'error' => '',
                'response' => [
                    'item' => [['item_id' => 201, 'item_status' => 'NORMAL']],
                    'has_next_page' => false,
                    'next_offset' => 0,
                ],
            ], 200),
            '*/api/v2/product/get_item_base_info*' => Http::response([
                'error' => '',
                'response' => [
                    'item_list' => [[
                        'item_id' => 201,
                        'item_name' => 'Shopee Variant Case',
                        'item_sku' => 'SHP-BLACK-11',
                        'has_model' => true,
                        'image' => ['image_url_list' => ['https://img.shopee/variant.jpg']],
                    ]],
                ],
            ], 200),
            '*/api/v2/product/get_model_list*' => Http::response([
                'error' => '',
                'response' => [
                    'model' => [
                        ['model_id' => 1, 'model_sku' => 'SHP-BLACK-11'],
                        ['model_id' => 2, 'model_sku' => 'SHP-WHITE-11'],
                    ],
                    'tier_variation' => [],
                ],
            ], 200),
        ]);

        $results = app(\Modules\Channel\Services\ShopeeProductService::class)
            ->searchProducts('SP-SHOP-1', 'SHP-WHITE-11', 3);

        $this->assertCount(1, $results);
        $this->assertSame('201', $results[0]['external_product_id']);
        $this->assertSame('SHP-WHITE-11', $results[0]['seller_sku']);
        $this->assertSame(['SHP-BLACK-11', 'SHP-WHITE-11'], $results[0]['seller_skus']);
    }
}
