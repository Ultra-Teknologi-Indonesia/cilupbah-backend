<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Services\ChannelDownloadService;
use Tests\TestCase;

class BatchedDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.shopee.partner_id' => '200123',
            'services.shopee.partner_key' => 'test_partner_key',
            'services.shopee.host' => 'https://partner.shopeemobile.com',
        ]);
    }

    private function shop(): ChannelShop
    {
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);

        return ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => '778899',
            'shop_name' => 'Toko',
            'access_token' => 'valid',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addHours(4),
            'is_active' => true,
        ]);
    }

    public function test_batched_discovery_fans_out_chunk_jobs(): void
    {
        putenv('DOWNLOAD_BATCH_CHUNK=2');
        $_ENV['DOWNLOAD_BATCH_CHUNK'] = '2';
        Bus::fake();
        $shop = $this->shop();

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_list*' => Http::response([
                'response' => [
                    'item' => [['item_id' => 1], ['item_id' => 2], ['item_id' => 3]],
                    'total_count' => 3,
                    'has_next_page' => false,
                    'next_offset' => 3,
                ],
            ], 200),
        ]);

        $trx = DownloadTransaction::create([
            'channel_shop_id' => $shop->id,
            'state' => DownloadTransaction::STATE_QUEUED,
        ]);

        app(ChannelDownloadService::class)->dispatchBatched($trx, 'shopee', '778899');

        $trx->refresh();
        $this->assertEquals(3, $trx->all_product);
        $this->assertEquals(DownloadTransaction::STATE_DOWNLOADING, $trx->state);
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 2);

        putenv('DOWNLOAD_BATCH_CHUNK');
        unset($_ENV['DOWNLOAD_BATCH_CHUNK']);
    }

    public function test_download_chunk_persists_and_counts(): void
    {
        $shop = $this->shop();

        Http::fake([
            'partner.shopeemobile.com/api/v2/product/get_item_base_info*' => Http::response([
                'response' => ['item_list' => [
                    ['item_id' => 1, 'item_name' => 'Produk A', 'item_sku' => 'SKU-A', 'has_model' => false, 'price_info' => [['current_price' => 1000]], 'image' => ['image_url_list' => []]],
                    ['item_id' => 2, 'item_name' => 'Produk B', 'item_sku' => 'SKU-B', 'has_model' => false, 'price_info' => [['current_price' => 2000]], 'image' => ['image_url_list' => []]],
                ]],
            ], 200),
        ]);

        $result = app(ChannelDownloadService::class)->downloadChunk('shopee', '778899', ['1', '2']);

        $this->assertEquals(2, $result['downloaded']);
        $this->assertEquals(0, $result['failed']);
        $this->assertDatabaseHas('products', ['name' => 'Produk A']);
        $this->assertDatabaseHas('products', ['name' => 'Produk B']);
    }
}
