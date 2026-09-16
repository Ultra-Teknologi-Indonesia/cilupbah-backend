<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WooCommerceOrderService;
use Modules\Sales\Jobs\AdminAlertJob;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class RefreshChannelOrderJobTest extends TestCase
{
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
            ->with('SHOP-1', 'ORDER-1')
            ->andReturn(1);

        $orders = new ChannelOrderRefreshService(
            $shopee,
            \Mockery::mock(TikTokOrderService::class),
            \Mockery::mock(LazadaOrderService::class),
            \Mockery::mock(WooCommerceOrderService::class),
        );

        $job = new RefreshChannelOrderJob('shopee', 'SHOP-1', 'ORDER-1');
        $job->handle($orders);

        self::assertSame('shopee:SHOP-1:ORDER-1', $job->uniqueId());
        self::assertSame(config('queue.names.shopee_orders'), $job->queue);
        self::assertSame(0, $job->tries);
        self::assertCount(1, $job->middleware());
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
        self::assertGreaterThanOrEqual(now()->addHours(23)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }
}
