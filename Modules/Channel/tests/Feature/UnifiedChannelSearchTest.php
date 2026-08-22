<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
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
        // Mock Shopee API
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

        // Create Master Product mapped to the Shopee one
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

        // Verify Shopee item is marked already_downloaded = true
        $shopeeItem = collect($items)->firstWhere('channel_code', 'shopee');
        $this->assertNotNull($shopeeItem);
        $this->assertSame('101', (string) $shopeeItem['external_product_id']);
        $this->assertTrue($shopeeItem['already_downloaded']);
        $this->assertSame($masterProduct->id, $shopeeItem['master_product_id']);

        // Verify TikTok item is marked already_downloaded = false
        $tiktokItem = collect($items)->firstWhere('channel_code', 'tiktok');
        $this->assertNotNull($tiktokItem);
        $this->assertSame('tt-prod-202', (string) $tiktokItem['external_product_id']);
        $this->assertFalse($tiktokItem['already_downloaded']);
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
}
