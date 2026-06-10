<?php

namespace Modules\Product\Tests\Feature;

use Tests\TestCase;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductSyncLog;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

class ProductHistoriesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;
    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'HSHOP-1',
            'access_token' => 'tok',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Logged Product',
            'sku' => 'LOG-SKU-1',
            'category_id' => 1,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        ProductSyncLog::record([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
        ]);
        ProductSyncLog::record([
            'product_id' => $this->product->id,
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_UPLOAD,
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => 'gagal',
        ]);
        ProductSyncLog::record([
            'channel_shop_id' => $this->shop->id,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'response' => ['pulled_count' => 3],
        ]);
    }

    public function test_upload_histories_returns_only_upload_logs()
    {
        $response = $this->getJson('/api/v1/upload-histories');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $ids = collect($response->json('data'))->pluck('item_group_id')->all();
        $this->assertContains($this->product->id, $ids);
    }

    public function test_download_histories_returns_only_download_logs()
    {
        $response = $this->getJson('/api/v1/download-histories');

        $response->assertStatus(200);
        $actions = collect($response->json('data'))->pluck('action')->unique()->values()->all();
        $this->assertSame(['download'], $actions);
    }

    public function test_upload_histories_filter_by_status()
    {
        $response = $this->getJson('/api/v1/upload-histories?filter[status]=failed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.success'));
        $this->assertSame('gagal', $response->json('data.0.status_message'));
    }

    public function test_upload_histories_filter_by_channel()
    {
        $response = $this->getJson('/api/v1/upload-histories?filter[channel]=tiktok');
        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));

        $empty = $this->getJson('/api/v1/upload-histories?filter[channel]=shopee');
        $empty->assertStatus(200);
        $this->assertCount(0, $empty->json('data'));
    }

    public function test_upload_histories_search_by_product()
    {
        $response = $this->getJson('/api/v1/upload-histories?search=Logged');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }
}
