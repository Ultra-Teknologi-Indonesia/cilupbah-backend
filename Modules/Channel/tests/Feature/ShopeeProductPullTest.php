<?php

namespace Modules\Channel\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ShopeeProductService;
use Tests\TestCase;

class ShopeeProductPullTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChannelShop $shop;
    private Channel $shopee;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);

        $this->user = $this->createPrivilegedUser();
        $this->shopee = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $this->shopee->id,
            'shop_id' => '778899',
            'shop_name' => 'Shopee 778899',
            'access_token' => 'valid-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    private function fakeWithModels(): void
    {
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
                        'item_name' => 'Kaos Shopee',
                        'description' => 'Bahan katun',
                        'category_id' => 100182,
                        'item_status' => 'NORMAL',
                        'item_sku' => 'PARENT-A',
                        'has_model' => true,
                        'condition' => 'NEW',
                        'image' => ['image_url_list' => ['https://img.shopee/1.jpg']],
                        'price_info' => [['current_price' => 50000]],
                    ]],
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_model_list*' => Http::response([
                'response' => [
                    'tier_variation' => [[
                        'name' => 'Warna',
                        'option_list' => [
                            ['option' => 'Merah', 'image' => ['image_url' => 'https://img.shopee/var-merah.jpg']],
                        ],
                    ]],
                    'model' => [[
                        'model_id' => 777100,
                        'model_sku' => 'VAR-A',
                        'tier_index' => [0],
                        'price_info' => [['current_price' => 55000]],
                        'original_price' => 55000,
                    ]],
                ],
            ], 200),
        ]);
    }

    public function test_pull_products_creates_download_product_and_mappings(): void
    {
        $this->fakeWithModels();

        $count = app(ShopeeProductService::class)->pullProducts('778899');

        $this->assertEquals(1, $count);

        $variant = DB::table('product_variants')->where('sku', 'VAR-A')->first();
        $this->assertNotNull($variant);

        $product = DB::table('products')->where('id', $variant->product_id)->first();
        $this->assertEquals('master', $product->status);
        $this->assertEquals('Kaos Shopee', $product->name);
        $this->assertNotNull($product->category_id, 'kategori harus ter-resolve (fallback bila perlu)');

        $media = DB::table('product_media')
            ->where('product_id', $product->id)
            ->whereNull('variant_id')
            ->where('media_type', 'image')
            ->get();
        $this->assertCount(1, $media);
        $this->assertEquals('https://img.shopee/1.jpg', $media->first()->url);

        $pcm = DB::table('product_channel_mappings')
            ->where('product_id', $product->id)
            ->where('channel_shop_id', $this->shop->id)
            ->first();
        $this->assertEquals('555100', $pcm->external_product_id);

        $pvcm = DB::table('product_variant_channel_mappings')
            ->where('product_channel_mapping_id', $pcm->id)
            ->where('variant_id', $variant->id)
            ->first();
        $this->assertEquals('777100', $pvcm->external_sku_id);
        $this->assertEquals('VAR-A', $pvcm->channel_seller_sku);

        $variantMedia = DB::table('product_media')
            ->where('product_id', $product->id)
            ->where('variant_id', $variant->id)
            ->where('media_type', 'image')
            ->first();
        $this->assertNotNull($variantMedia, 'gambar varian harus tersimpan dengan variant_id');
        $this->assertEquals('https://img.shopee/var-merah.jpg', $variantMedia->url);
    }

    public function test_pull_product_by_id_handles_item_without_models(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_base_info*' => Http::response([
                'response' => [
                    'item_list' => [[
                        'item_id' => 555200,
                        'item_name' => 'Topi Shopee',
                        'description' => 'Topi keren',
                        'category_id' => 100182,
                        'item_status' => 'NORMAL',
                        'item_sku' => 'TOPI-1',
                        'has_model' => false,
                        'condition' => 'NEW',
                        'image' => ['image_url_list' => ['https://img.shopee/topi.jpg']],
                        'price_info' => [['current_price' => 30000]],
                    ]],
                ],
            ], 200),
        ]);

        $ok = app(ShopeeProductService::class)->pullProductById('778899', '555200');

        $this->assertTrue($ok);

        $variant = DB::table('product_variants')->where('sku', 'TOPI-1')->first();
        $this->assertNotNull($variant);

        $pcm = DB::table('product_channel_mappings')
            ->where('channel_shop_id', $this->shop->id)
            ->where('external_product_id', '555200')
            ->first();
        $this->assertNotNull($pcm);

        $this->assertDatabaseHas('product_sync_logs', [
            'channel_shop_id' => $this->shop->id,
            'action' => 'download',
            'status' => 'success',
        ]);
    }

    public function test_search_products_filters_by_query(): void
    {
        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_list*' => Http::response([
                'response' => [
                    'item' => [
                        ['item_id' => 1, 'item_status' => 'NORMAL'],
                        ['item_id' => 2, 'item_status' => 'NORMAL'],
                    ],
                    'has_next_page' => false,
                    'next_offset' => 2,
                ],
            ], 200),
            'partner.shopeemobile.com/api/v2/product/get_item_base_info*' => Http::response([
                'response' => [
                    'item_list' => [
                        ['item_id' => 1, 'item_name' => 'Kaos Merah', 'item_sku' => 'KM-1', 'image' => ['image_url_list' => ['https://img/kaos.jpg']]],
                        ['item_id' => 2, 'item_name' => 'Celana Biru', 'item_sku' => 'CB-1', 'image' => ['image_url_list' => []]],
                    ],
                ],
            ], 200),
        ]);

        $results = app(ShopeeProductService::class)->searchProducts('778899', 'kaos');

        $this->assertCount(1, $results);
        $this->assertEquals('1', $results[0]['external_product_id']);
        $this->assertEquals('Kaos Merah', $results[0]['name']);
        $this->assertEquals('shopee', $results[0]['channel_code']);
    }

    public function test_generic_download_endpoint_accepts_shopee(): void
    {
        Queue::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/shopee/download', ['shop_id' => '778899'])
            ->assertStatus(202);

        Queue::assertPushed(DownloadProductsJob::class);
    }
}
