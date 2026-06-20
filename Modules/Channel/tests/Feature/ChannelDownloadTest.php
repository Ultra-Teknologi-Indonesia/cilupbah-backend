<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Services\ChannelDownloadService;
use Tests\TestCase;

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

        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Cat']);

        ChannelShop::create([
            'shop_id' => 'SHOP-DL-1',
            'access_token' => 'access-token',
            'is_active' => true,
        ]);

        $detail = [
            'id' => 'TIKTOK-PROD-1',
            'title' => 'Downloaded Phone',
            'status' => 'ACTIVATE',
            'description' => 'Deskripsi produk',
            'skus' => [
                [
                    'id' => 'SKU-EXT-1',
                    'seller_sku' => 'DL-SKU-1',
                    'price' => ['tax_exclusive_price' => 10000],
                    'inventory' => [['quantity' => 5]],
                ],
            ],
            'main_images' => [['urls' => ['https://img/1.jpg']]],
        ];

        Http::fake([
            // Detail per produk (dipakai pull untuk opsi varian, gambar, deskripsi).
            '*/products/TIKTOK-PROD-1*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'data' => $detail,
            ], 200),
            // Enumerasi id produk.
            '*products/search*' => Http::response([
                'code' => 0,
                'message' => 'Success',
                'data' => ['products' => [['id' => 'TIKTOK-PROD-1', 'title' => 'Downloaded Phone']]],
            ], 200),
        ]);
    }

    public function test_download_queues_transaction_and_job()
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/tiktok/download', ['shop_id' => 'SHOP-DL-1']);

        $response->assertStatus(202);
        $response->assertJsonPath('data.state', 'queued');

        $shop = ChannelShop::where('shop_id', 'SHOP-DL-1')->first();
        $this->assertDatabaseHas('download_transactions', [
            'channel_shop_id' => $shop->id,
            'state' => 'queued',
        ]);
        Queue::assertPushed(DownloadProductsJob::class);
    }

    public function test_pull_creates_products_with_download_status()
    {
        app(ChannelDownloadService::class)->pull('tiktok', 'SHOP-DL-1');

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
        $this->assertDatabaseHas('product_sync_logs', [
            'channel_shop_id' => $shop->id,
            'action' => 'download',
            'status' => 'success',
        ]);
    }

    public function test_job_marks_transaction_done()
    {
        $shop = ChannelShop::where('shop_id', 'SHOP-DL-1')->first();
        $trx = DownloadTransaction::create([
            'channel_shop_id' => $shop->id,
            'state' => 'queued',
        ]);

        (new DownloadProductsJob($trx->id, 'tiktok', 'SHOP-DL-1'))
            ->handle(app(ChannelDownloadService::class));

        $trx->refresh();
        $this->assertSame('done', $trx->state);
        $this->assertSame(1, $trx->total_downloaded);
        $this->assertSame(100, $trx->progress_percent);
    }

    public function test_download_requires_shop_id()
    {
        $this->postJson('/api/v1/tiktok/download', [])->assertStatus(422);
    }

    public function test_download_unsupported_channel_returns_422()
    {
        $response = $this->postJson('/api/v1/blibli/download', ['shop_id' => 'SHOP-DL-1']);
        $response->assertStatus(422);
        $response->assertJsonPath('status', 'error');
    }

    public function test_bulk_download_queues_transactions()
    {
        Queue::fake();

        ChannelShop::create([
            'shop_id' => 'SHOP-DL-2',
            'access_token' => 'access-token-2',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/tiktok/download/bulk', [
            'shop_ids' => ['SHOP-DL-1', 'SHOP-DL-2'],
        ]);

        $response->assertStatus(202);
        $response->assertJsonCount(2, 'data');
        $this->assertSame(2, DownloadTransaction::count());
        Queue::assertPushed(DownloadProductsJob::class, 2);
    }
}
