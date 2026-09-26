<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Jobs\DispatchChannelStockOutboxJob;
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

    public function test_stock_request_wakes_the_dedicated_outbox_dispatcher(): void
    {
        Queue::fake();
        $this->app->make(ChannelStockSyncOutboxService::class)
            ->request($this->listedMapping('LISTING-WAKE'), 'sync_stock');

        Queue::assertPushed(DispatchChannelStockOutboxJob::class);
    }

    public function test_superseded_stock_delivery_immediately_wakes_the_latest_version(): void
    {
        Queue::fake();
        $service = app(ChannelStockSyncOutboxService::class);
        $mapping = $this->listedMapping('LATEST-WAKE');
        $outbox = $service->request($mapping, 'sync_stock');
        $service->dispatchDue();
        $service->request($mapping, 'sync_stock');
        Queue::fake();

        $this->travel(6)->seconds();

        $this->assertFalse($service->shouldExecute($outbox->id, 1));
        Queue::assertPushed(DispatchChannelStockOutboxJob::class);
    }

    public function test_saturated_shop_cannot_hide_another_shops_due_stock(): void
    {
        Queue::fake();
        $service = app(ChannelStockSyncOutboxService::class);
        $first = $service->request($this->listedMapping('BUSY-ACTIVE'), 'sync_stock');
        $service->dispatchDue(1);
        $this->assertSame(ChannelStockSyncOutbox::STATUS_DISPATCHING, $first->fresh()->status);

        foreach (range(1, 4) as $index) {
            $service->request($this->listedMapping('BUSY-PENDING-'.$index), 'sync_stock');
        }
        $otherShop = $this->shop->replicate();
        $otherShop->shop_id = 'OTHER-SHOP';
        $otherShop->save();
        $this->shop = $otherShop;
        $eligible = $service->request($this->listedMapping('OTHER-READY'), 'sync_stock');
        Queue::fake();

        $this->assertSame(1, $service->dispatchDue(1)['claimed']);
        Queue::assertPushed(SyncProductToChannelJob::class, fn ($job): bool => $job->stockOutboxId === $eligible->id);
    }

    public function test_scheduler_and_wake_job_cannot_spend_the_same_shop_budget_concurrently(): void
    {
        Queue::fake();
        $service = app(ChannelStockSyncOutboxService::class);
        $service->request($this->listedMapping('DISPATCH-LOCK'), 'sync_stock');
        $lock = Cache::lock('channel-stock-outbox:dispatch', 60);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(0, $service->dispatchDue()['claimed']);
            Queue::assertNotPushed(SyncProductToChannelJob::class);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $service->dispatchDue()['claimed']);
    }

    public function test_dispatcher_paces_jobs_per_shop_before_they_enter_redis(): void
    {
        $service = app(ChannelStockSyncOutboxService::class);
        config(['channel.stock_sync_max_inflight_per_shop' => 3]);
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

    public function test_dispatcher_does_not_claim_more_than_the_shop_inflight_budget(): void
    {
        $service = app(ChannelStockSyncOutboxService::class);
        foreach (['LISTING-INFLIGHT-1', 'LISTING-INFLIGHT-2'] as $externalId) {
            $service->request($this->listedMapping($externalId), 'sync_stock');
        }

        Queue::fake();
        $result = $service->dispatchDue();

        $this->assertSame(1, $result['claimed']);
        $this->assertSame(1, ChannelStockSyncOutbox::where('status', ChannelStockSyncOutbox::STATUS_DISPATCHING)->count());
        $this->assertSame(1, ChannelStockSyncOutbox::where('status', ChannelStockSyncOutbox::STATUS_PENDING)->count());
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

    public function test_an_expired_lease_is_reissued_as_a_new_generation(): void
    {
        $mapping = $this->listedMapping('LISTING-EXPIRED-LEASE');
        $service = app(ChannelStockSyncOutboxService::class);

        $service->request($mapping, 'sync_stock');
        Queue::fake();
        $service->dispatchDue();

        $outbox = ChannelStockSyncOutbox::where('product_channel_mapping_id', $mapping->id)->firstOrFail();
        $outbox->update(['lease_expires_at' => now()->subSecond()]);

        $result = $service->dispatchDue();

        $this->assertSame(1, $result['reaped']);
        $this->assertSame(1, $result['claimed']);
        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'id' => $outbox->id,
            'status' => ChannelStockSyncOutbox::STATUS_DISPATCHING,
            'requested_version' => 2,
            'dispatched_version' => 2,
        ]);
        Queue::assertPushed(SyncProductToChannelJob::class, function (SyncProductToChannelJob $job) use ($outbox): bool {
            return $job->stockOutboxId === $outbox->id
                && $job->stockOutboxVersion === 2
                && $job->uniqueId() === 'product-sync:stock-outbox:'.$outbox->id.':2';
        });
    }

    public function test_expired_dispatching_leases_can_be_reaped_without_dispatching_to_marketplace(): void
    {
        $mapping = $this->listedMapping('LISTING-REAP-ONLY');
        $service = app(ChannelStockSyncOutboxService::class);

        $service->request($mapping, 'sync_stock');
        Queue::fake();
        $service->dispatchDue();

        $outbox = ChannelStockSyncOutbox::where('product_channel_mapping_id', $mapping->id)->firstOrFail();
        $outbox->update([
            'lease_expires_at' => now()->subMinute(),
        ]);

        $this->assertSame(1, $service->expiredDispatchingCount());

        $reaped = $service->reapExpiredLeases();

        $this->assertSame(1, $reaped);
        $this->assertSame(0, $service->expiredDispatchingCount());
        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'id' => $outbox->id,
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 2,
            'dispatched_version' => 1,
        ]);
        Queue::assertPushed(SyncProductToChannelJob::class, 1);
    }

    public function test_a_pending_delivery_left_by_an_old_expired_lease_is_revived_safely(): void
    {
        $mapping = $this->listedMapping('LISTING-STRANDED-PENDING');
        $service = app(ChannelStockSyncOutboxService::class);

        $outbox = $service->request($mapping, 'sync_stock');
        $outbox->update([
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 1,
            'dispatched_version' => 1,
            'next_attempt_at' => now()->subSecond(),
            'lease_expires_at' => null,
        ]);

        Queue::fake();
        $result = $service->dispatchDue();

        $this->assertSame(1, $result['revived']);
        $this->assertSame(1, $result['claimed']);
        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'id' => $outbox->id,
            'status' => ChannelStockSyncOutbox::STATUS_DISPATCHING,
            'requested_version' => 2,
            'dispatched_version' => 2,
        ]);
    }

    public function test_a_legacy_listing_stock_job_is_converted_to_a_durable_outbox_request_without_calling_an_api(): void
    {
        $mapping = $this->listedMapping('LISTING-LEGACY-QUEUE');
        $job = new SyncProductToChannelJob(
            $mapping->product_id,
            $mapping->channel_shop_id,
            'sync_stock',
            channelMappingId: $mapping->id,
        );

        $this->assertSame([], $job->middleware());
        $job->handle(app(AdapterFactory::class));

        $this->assertDatabaseHas('channel_stock_sync_outbox', [
            'product_channel_mapping_id' => $mapping->id,
            'status' => ChannelStockSyncOutbox::STATUS_PENDING,
            'requested_version' => 1,
            'queue_tier' => 'critical',
        ]);
    }

    public function test_successful_stock_updates_do_not_exhaust_the_retry_budget(): void
    {
        Queue::fake();
        config(['channel.stock_sync_max_attempts' => 12]);
        $mapping = $this->listedMapping('SUCCESS-BUDGET');
        $service = app(ChannelStockSyncOutboxService::class);
        for ($cycle = 1; $cycle <= 13; $cycle++) {
            $outbox = $service->request($mapping, 'sync_stock');
            $service->dispatchDue();
            $version = $outbox->fresh()->dispatched_version;
            $this->assertTrue($service->shouldExecute($outbox->id, $version), "Delivery {$cycle}");
            $service->succeed($outbox->id, $version);
            $this->assertSame(0, $outbox->fresh()->attempt_count);
        }
    }

    public function test_recovery_honors_backoff_and_reissues_only_when_due(): void
    {
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-09-24T10:00:00Z'));
        $timezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            DB::statement("SET LOCAL TIME ZONE 'UTC'");
            $service = app(ChannelStockSyncOutboxService::class);
            $outbox = $service->request($this->listedMapping('BACKOFF'), 'sync_stock');
            $service->dispatchDue();
            $this->assertTrue($service->shouldExecute($outbox->id, 1));
            $service->defer($outbox->id, 1, 'Rate limit', 300);
            $this->assertSame(0, $service->dispatchDue()['claimed']);
            $this->travel(300)->seconds();
            $this->assertSame(1, $service->dispatchDue()['claimed']);
            $this->assertSame(2, $outbox->fresh()->dispatched_version);
            $this->assertSame(1, $outbox->fresh()->attempt_count);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function test_superseded_success_resets_failures_but_keeps_latest_stock_pending(): void
    {
        Queue::fake();
        $service = app(ChannelStockSyncOutboxService::class);
        $mapping = $this->listedMapping('SUCCESS-LATEST');
        $outbox = $service->request($mapping, 'sync_stock');
        $service->dispatchDue();
        $this->assertTrue($service->shouldExecute($outbox->id, 1));
        $service->request($mapping, 'sync_stock');
        $service->succeed($outbox->id, 1);
        $this->assertSame(0, $outbox->fresh()->attempt_count);
        $this->assertSame(ChannelStockSyncOutbox::STATUS_PENDING, $outbox->fresh()->status);
        $this->assertTrue($outbox->fresh()->sync_stock);
    }

    public function test_retry_budget_still_stops_repeated_failures(): void
    {
        Queue::fake();
        config(['channel.stock_sync_max_attempts' => 2]);
        $service = app(ChannelStockSyncOutboxService::class);
        $outbox = $service->request($this->listedMapping('FAILURE-BUDGET'), 'sync_stock');
        $service->dispatchDue();
        $outbox->update(['attempt_count' => 2]);
        $service->defer($outbox->id, 1, 'API unavailable', 60);
        $this->assertSame(ChannelStockSyncOutbox::STATUS_FAILED, $outbox->fresh()->status);
        $this->assertSame(0, $service->dispatchDue()['claimed']);
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
