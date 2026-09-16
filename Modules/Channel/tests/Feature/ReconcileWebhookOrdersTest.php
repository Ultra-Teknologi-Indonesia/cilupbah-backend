<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

final class ReconcileWebhookOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_finds_processed_webhook_without_local_order(): void
    {
        Queue::fake();
        $this->makeWebhook('missing-order', 'TT-MISSING-1');

        $this->artisan('channel:reconcile-webhook-orders', [
            '--hours' => 48,
        ])->expectsOutputToContain('HILANG [tiktok] shop=TT1 order=TT-MISSING-1')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_fix_queues_only_the_missing_order_using_the_original_event_key(): void
    {
        Queue::fake();
        $this->makeWebhook('missing-order', 'TT-MISSING-2');
        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => 'tiktok-event-present',
            'event_type' => '1',
            'payload' => [
                'type' => 1,
                'shop_id' => 'TT1',
                'data' => ['order_id' => 'TT-PRESENT-1'],
            ],
            'status' => WebhookInboxStatus::PROCESSED,
            'received_at' => now(),
            'processed_at' => now(),
        ]);
        SalesOrder::factory()->create([
            'source' => 'tiktok',
            'channel_shop_id' => 'TT1',
            'channel_order_no' => 'TT-PRESENT-1',
        ]);

        $this->artisan('channel:reconcile-webhook-orders', [
            '--hours' => 48,
            '--fix' => true,
        ])->assertSuccessful();

        Queue::assertPushed(RefreshChannelOrderJob::class, function (RefreshChannelOrderJob $job): bool {
            return $job->channel === 'tiktok'
                && $job->shopId === 'TT1'
                && $job->orderId === 'TT-MISSING-2'
                && $job->webhookEventKey === 'missing-order';
        });
        Queue::assertPushed(RefreshChannelOrderJob::class, 1);
    }

    private function makeWebhook(string $eventKey, string $orderId): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => $eventKey,
            'event_type' => '1',
            'payload' => [
                'type' => 1,
                'shop_id' => 'TT1',
                'data' => ['order_id' => $orderId],
            ],
            'status' => WebhookInboxStatus::PROCESSED,
            'received_at' => now(),
            'processed_at' => now(),
        ]);
    }
}
