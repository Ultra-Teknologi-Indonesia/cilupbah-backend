<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;
use Tests\TestCase;

class DownloadProgressTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        $channel = Channel::create(['code' => 'tiktok', 'name' => 'TikTok', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'SHOP-PRG-1',
            'shop_name' => 'Cilupbah ID Mall',
            'is_active' => true,
        ]);
    }

    public function test_list_returns_transactions_with_progress_fields()
    {
        DownloadTransaction::create([
            'channel_shop_id' => $this->shop->id,
            'state' => 'done',
            'all_product' => 5189,
            'total_downloaded' => 5189,
            'progress_percent' => 100,
        ]);

        $response = $this->getJson('/api/v1/download-transactions');

        $response->assertStatus(200);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonStructure([
            'data' => [[
                'trx_id',
                'trx_no',
                'executed_by',
                'store_name',
                'store_id',
                'channel_id',
                'created_date',
                'state',
                'is_downloaded',
                'on_download_process',
                'total_downloaded',
                'all_product',
                'progress_percent',
            ]],
        ]);

        $entry = $response->json('data.0');
        $this->assertStringStartsWith('DWNLD-', $entry['trx_no']);
        $this->assertTrue($entry['is_downloaded']);
        $this->assertFalse($entry['on_download_process']);
        $this->assertSame('Cilupbah ID Mall', $entry['store_name']);
        $this->assertSame(5189, $entry['total_downloaded']);
    }

    public function test_filter_by_state()
    {
        DownloadTransaction::create(['channel_shop_id' => $this->shop->id, 'state' => 'done']);
        DownloadTransaction::create(['channel_shop_id' => $this->shop->id, 'state' => 'downloading']);

        $response = $this->getJson('/api/v1/download-transactions?filter[state]=downloading');

        $response->assertStatus(200);
        $states = collect($response->json('data'))->pluck('state')->unique()->all();
        $this->assertSame(['downloading'], $states);
    }

    public function test_detail_returns_transaction_and_products()
    {
        $trx = DownloadTransaction::create([
            'channel_shop_id' => $this->shop->id,
            'state' => 'done',
            'all_product' => 1,
            'total_downloaded' => 1,
            'progress_percent' => 100,
        ]);

        $product = Product::create(['name' => 'Downloaded Liquid Case', 'category_id' => 1, 'status' => Product::STATUS_DOWNLOAD, 'is_active' => true]);
        ProductVariant::create(['product_id' => $product->id, 'sku' => 'LSM-A', 'sell_price' => 1000, 'is_active' => true]);
        ProductMedia::create(['product_id' => $product->id, 'media_type' => 'image', 'url' => 'https://img/case.jpg', 'is_primary' => true, 'sort_order' => 1]);
        ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => 'EXT-PRD-1',
            'sync_status' => 'synced',
        ]);

        $response = $this->getJson("/api/v1/download-transactions/{$trx->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.transaction.trx_no', $trx->trx_no);
        $response->assertJsonPath('data.state', 'done');
        $response->assertJsonPath('data.count', 1);

        $item = $response->json('data.products.0');
        $this->assertSame('Downloaded Liquid Case', $item['item_name']);
        $this->assertSame('LSM-A', $item['item_code']);
        $this->assertSame('EXT-PRD-1', $item['channel_group_id']);
        $this->assertSame('https://img/case.jpg', $item['img_url']);
        $this->assertFalse($item['is_master']);
    }

    public function test_detail_marks_master_by_status_and_filters_by_it()
    {
        $trx = DownloadTransaction::create([
            'channel_shop_id' => $this->shop->id,
            'state' => 'done',
            'all_product' => 2,
            'total_downloaded' => 2,
            'progress_percent' => 100,
        ]);

        $master = Product::create(['name' => 'Master Case', 'category_id' => 1, 'status' => Product::STATUS_MASTER, 'is_active' => true]);
        ProductChannelMapping::create(['product_id' => $master->id, 'channel_shop_id' => $this->shop->id, 'external_product_id' => 'EXT-M', 'sync_status' => 'synced']);

        $belum = Product::create(['name' => 'Belum Case', 'category_id' => 1, 'status' => Product::STATUS_DOWNLOAD, 'is_active' => true]);
        ProductChannelMapping::create(['product_id' => $belum->id, 'channel_shop_id' => $this->shop->id, 'external_product_id' => 'EXT-D', 'sync_status' => 'synced']);

        $items = collect($this->getJson("/api/v1/download-transactions/{$trx->id}")->json('data.products'))
            ->keyBy('item_name');
        $this->assertTrue($items['Master Case']['is_master']);
        $this->assertFalse($items['Belum Case']['is_master']);

        $masterOnly = $this->getJson("/api/v1/download-transactions/{$trx->id}?filter[is_master]=1")->json('data.products');
        $this->assertCount(1, $masterOnly);
        $this->assertSame('Master Case', $masterOnly[0]['item_name']);

        $belumOnly = $this->getJson("/api/v1/download-transactions/{$trx->id}?filter[is_master]=0")->json('data.products');
        $this->assertCount(1, $belumOnly);
        $this->assertSame('Belum Case', $belumOnly[0]['item_name']);
    }

    public function test_detail_unknown_returns_404()
    {
        $this->getJson('/api/v1/download-transactions/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404);
    }
}
