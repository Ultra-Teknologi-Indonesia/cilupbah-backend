<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class BackfillDownloadHistoryTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->categoryId = Category::create(['name' => 'Cat', 'is_active' => true])->id;
        $channel = Channel::create(['code' => 'shopee', 'name' => 'Shopee', 'is_active' => true]);
        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id, 'shop_id' => 'SP-1', 'shop_name' => 'Toko',
            'access_token' => 't', 'is_active' => true,
        ]);
    }

    private function mapProduct(string $externalId, string $at, string $status = Product::STATUS_MASTER): void
    {
        $product = Product::create(['name' => 'P-' . $externalId, 'category_id' => $this->categoryId, 'status' => $status, 'is_active' => true]);
        $mapping = ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => $externalId,
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);
        DB::table('product_channel_mappings')->where('id', $mapping->id)->update(['created_at' => $at]);
    }

    public function test_per_product_creates_one_transaction_per_mapping_with_mapping_timestamp(): void
    {
        $this->mapProduct('A', '2026-07-01 10:00:00');
        $this->mapProduct('B', '2026-07-02 11:30:00');

        $this->artisan('channel:backfill-download-history')->assertSuccessful();

        $this->assertSame(2, DownloadTransaction::count());

        $trx = DownloadTransaction::orderBy('created_at')->get();
        $this->assertSame('done', $trx[0]->state);
        $this->assertSame(1, $trx[0]->total_downloaded);
        $this->assertSame(100, $trx[0]->progress_percent);
        $this->assertNull($trx[0]->executed_by);
        $this->assertSame('2026-07-01 10:00:00', Carbon::parse($trx[0]->created_at)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-02 11:30:00', Carbon::parse($trx[1]->created_at)->format('Y-m-d H:i:s'));
    }

    public function test_per_shop_aggregates_into_one_transaction(): void
    {
        $this->mapProduct('A', '2026-07-01 10:00:00');
        $this->mapProduct('B', '2026-07-02 11:30:00');

        $this->artisan('channel:backfill-download-history --per-shop')->assertSuccessful();

        $this->assertSame(1, DownloadTransaction::count());
        $trx = DownloadTransaction::first();
        $this->assertSame(2, $trx->total_downloaded);
        $this->assertSame(2, $trx->all_product);
        $this->assertSame('2026-07-02 11:30:00', Carbon::parse($trx->created_at)->format('Y-m-d H:i:s'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->mapProduct('A', '2026-07-01 10:00:00');

        $this->artisan('channel:backfill-download-history --dry-run')->assertSuccessful();

        $this->assertSame(0, DownloadTransaction::count());
    }

    public function test_guard_blocks_when_history_already_exists(): void
    {
        $this->mapProduct('A', '2026-07-01 10:00:00');
        DownloadTransaction::create([
            'channel_shop_id' => $this->shop->id,
            'state' => DownloadTransaction::STATE_DONE,
        ]);

        $this->artisan('channel:backfill-download-history')->assertFailed();

        $this->assertSame(1, DownloadTransaction::count());

        $this->artisan('channel:backfill-download-history --force')->assertSuccessful();
        $this->assertSame(2, DownloadTransaction::count());
    }

    public function test_skips_products_not_master_or_download(): void
    {
        $this->mapProduct('A', '2026-07-01 10:00:00', Product::STATUS_ARCHIVED);

        $this->artisan('channel:backfill-download-history')->assertSuccessful();

        $this->assertSame(0, DownloadTransaction::count());
    }
}
