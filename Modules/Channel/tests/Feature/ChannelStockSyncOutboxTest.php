<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Channel\Models\Channel;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Models\ChannelStockSyncOutbox;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Tests\TestCase;

class ChannelStockSyncOutboxTest extends TestCase
{
    use RefreshDatabase;

    private ChannelShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Channel::create([
            'code' => 'shopee',
            'name' => 'Shopee',
            'is_active' => true,
        ]);

        $this->shop = ChannelShop::create([
            'channel_id' => $channel->id,
            'shop_id' => 'OUTBOX-SHOP',
            'shop_name' => 'Outbox shop',
            'is_active' => true,
            'stock_push_enabled' => true,
            'price_push_enabled' => true,
        ]);

        config([
            'channel.stock_sync_dispatch_window_seconds' => 2,
            'ratelimit.channel_api_per_second_by_channel.shopee' => 2,
        ]);
    }

    public function test_newer_changes_coalesce_per_listing_and_preserve_the_higher_priority_lane(): void
    {
        $mapping = $this->listedMapping('LISTING-1');
        $service = app(ChannelStockSyncOutboxService::class);

        $service->request($mapping, 'sync_stock', 'bulk');
        $service->request($mapping, 'sync_price', 'critical');

        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'product_channel_mapping_id' => $mapping->id,
            'sync_stock' => true,
            'sync_price' => true,
            'queue_tier' => 'critical',
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 2,
        ]);

        $outbox = ChannelStockSyncOutbox::where('product_channel_mapping_id', $mapping->id)->firstOrFail();
        $this->assertSame('sync_price_stock', $outbox->action());
    }

    public function test_dispatcher_paces_jobs_per_shop_before_they_enter_redis(): void
    {
        $service = app(ChannelStockSyncOutboxService::class);
        foreach (['LISTING-1', 'LISTING-2', 'LISTING-3'] as $externalId) {
            $service->request($this->listedMapping($externalId), 'sync_stock');
        }

        Queue::fake();
        $result = $service->dispatchDue();

        $this->assertSame(3, $result['claimed']);
        $this->assertSame(['shopee' => 3], $result['byChannel']);
        Queue::assertPushed(SyncProductToChannelJob::class, 3);

        Queue::assertPushed(SyncProductToChannelJob::class, function (SyncProductToChannelJob $job): bool {
            return $job->stockOutboxId !== null
                && $job->stockOutboxVersion === 1
                && $job->middleware() === [];
        });

        $this->assertSame(3, ChannelStockSyncOutbox::where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)->count());
    }

    public function test_a_job_waiting_in_redis_cannot_write_after_a_newer_stock_change_arrives(): void
    {
        $mapping = $this->listedMapping('LISTING-STALE');
        $service = app(ChannelStockSyncOutboxService::class);

        $service->request($mapping, 'sync_stock');
        Queue::fake();
        $service->dispatchDue();

        $outbox = ChannelStockSyncOutbox::where('product_channel_mapping_id', $mapping->id)->firstOrFail();
        $this->assertSame(1, $outbox->dispatched_version);

        $service->request($mapping, 'sync_stock');
        $this->assertFalse($service->shouldExecute($outbox->id, 1));

        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'id' => $outbox->id,
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 2,
            'dispatched_version' => 1,
        ]);
    }

    private function listedMapping(string $externalId): ProductChannelMapping
    {
        $category = Category::firstOrCreate([
            'name' => 'Outbox category',
        ], [
            'is_active' => true,
        ]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk '.$externalId,
            'status' => Product::STATUS_MASTER,
            'is_active' => true,
        ]);

        return ProductChannelMapping::create([
            'product_id' => $product->id,
            'channel_shop_id' => $this->shop->id,
            'external_product_id' => $externalId,
            'sync_status' => ProductChannelMapping::STATUS_SYNCED,
        ]);
    }
}
