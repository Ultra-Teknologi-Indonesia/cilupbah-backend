<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class RefreshChannelOrderJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);
        app(ChannelSyncSettingService::class)->setEnabled(true);
    }

    protected function tearDown(): void
    {
        Cache::forget(ChannelSyncSettingService::CACHE_KEY);
        parent::tearDown();
    }

    public function test_it_refreshes_the_order_using_the_latest_channel_detail(): void
    {
        SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-1',
            'channel_order_no' => 'ORDER-1',
        ]);

        $shopee = \Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('pullOrderById')
            ->once()
            ->with('SHOP-1', 'ORDER-1', true)
            ->andReturn(1);

        $orders = new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
            app(ChannelSyncSettingService::class),
        );

        $job = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1');
        $job->handle($orders, app(ChannelSyncSettingService::class));

        self::assertSame('shopee:SHOP-1:ORDER-1', $job->uniqueId());
        self::assertSame(config('queue.names.channel_order_refresh'), $job->queue);
        self::assertSame(8, $job->tries);
        self::assertCount(2, $job->middleware());
    }

    public function test_a_new_status_event_during_an_active_refresh_is_not_discarded(): void
    {
        Queue::fake();
        SalesOrder::factory()->create([
            'source' => 'shopee', 'channel_shop_id' => 'SHOP-1', 'channel_order_no' => 'ORDER-1',
        ]);
        $shopee = \Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('pullOrderById')->once()->andReturnUsing(function (): int {

            RefreshChannelOrderJob::dispatch('shopee', 'SHOP-1', 'ORDER-1', null, 'latest-event');

            return 1;
        });
        $this->app->instance(ChannelOrderRefreshService::class, new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
            app(ChannelSyncSettingService::class),
        ));
        $command = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1');
        $this->assertTrue((new UniqueLock(app(CacheRepository::class)))->acquire($command));
        $queuedJob = \Mockery::mock(Job::class);
        $queuedJob->shouldReceive('isReleased', 'hasFailed', 'isDeletedOrReleased')->andReturn(false);
        $queuedJob->shouldReceive('delete')->once();

        app(CallQueuedHandler::class)->call($queuedJob, ['command' => serialize($command)]);

        Queue::assertPushed(RefreshChannelOrderJob::class, fn ($job): bool => $job->webhookEventKey === 'latest-event');
    }

    public function test_it_skips_refresh_while_channel_sync_is_paused(): void
    {
        app(ChannelSyncSettingService::class)->setEnabled(false);

        $shopee = \Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('pullOrderById')->never();

        $orders = new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
            app(ChannelSyncSettingService::class),
        );

        $job = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1');
        $job->handle($orders, app(ChannelSyncSettingService::class));

        self::assertTrue(app(ChannelSyncSettingService::class)->isPaused());
    }

    public function test_it_finishes_a_webhook_refresh_that_was_already_accepted_before_pause(): void
    {
        SalesOrder::factory()->create([
            'source' => 'shopee',
            'channel_shop_id' => 'SHOP-1',
            'channel_order_no' => 'ORDER-1',
        ]);

        app(ChannelSyncSettingService::class)->setEnabled(false);

        $shopee = \Mockery::mock(ShopeeOrderService::class);
        $shopee->shouldReceive('pullOrderById')
            ->once()
            ->with('SHOP-1', 'ORDER-1', true)
            ->andReturn(1);

        $orders = new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
            app(ChannelSyncSettingService::class),
        );

        $job = new RefreshChannelOrderJob(
            'shopee',
            'SHOP-1',
            'ORDER-1',
            null,
            'webhook-event-1',
        );

        $job->handle($orders, app(ChannelSyncSettingService::class));

        self::assertTrue(app(ChannelSyncSettingService::class)->isPaused());
    }

    public function test_permanent_refresh_failure_marks_the_original_webhook_failed(): void
    {
        Queue::fake();
        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'SHOP-1',
            'event_key' => 'tiktok-event-1',
            'event_type' => '1',
            'payload' => ['type' => 1],
            'status' => WebhookInboxStatus::PROCESSED,
            'received_at' => now(),
            'processed_at' => now(),
        ]);

        $job = new RefreshChannelOrderJob(
            'tiktok',
            'SHOP-1',
            'ORDER-1',
            null,
            'tiktok-event-1',
        );
        $job->failed(new \RuntimeException('order detail kosong'));

        $row = ChannelWebhookInbox::query()->where('event_key', 'tiktok-event-1')->firstOrFail();
        self::assertSame(WebhookInboxStatus::FAILED, $row->status);
        self::assertStringStartsWith('DOWNSTREAM_ORDER_REFRESH_FAILED:', (string) $row->error);
        Queue::assertPushed(AdminAlertJob::class, 1);
    }

    public function test_it_can_use_the_isolated_tracking_refresh_queue(): void
    {
        $job = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1', 'shopee-tracking');

        self::assertSame('shopee-tracking', $job->queue);
        self::assertGreaterThanOrEqual(now()->addHours(1)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }
}
