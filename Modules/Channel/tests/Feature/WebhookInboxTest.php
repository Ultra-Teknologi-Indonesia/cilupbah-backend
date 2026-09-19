<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use Modules\Channel\Services\ChannelWebhookService;
use Modules\Channel\Services\QueueCapacityReader;
use Modules\Sales\Jobs\AdminAlertJob;
use Tests\TestCase;

class WebhookInboxTest extends TestCase
{
    use RefreshDatabase;

    private function repo(): ChannelWebhookInboxRepository
    {
        return app(ChannelWebhookInboxRepository::class);
    }

    public function test_record_first_delivery_is_idempotent(): void
    {
        $first = $this->repo()->recordFirstDelivery('shopee', 'SH1', 'evt-1', '3', ['a' => 1]);
        $second = $this->repo()->recordFirstDelivery('shopee', 'SH1', 'evt-1', '3', ['a' => 1]);

        $this->assertNotNull($first);
        $this->assertNull($second);
        $this->assertSame(1, ChannelWebhookInbox::query()->where('event_key', 'evt-1')->count());
        $this->assertSame(WebhookInboxStatus::RECEIVED, $first->status);
    }

    public function test_mark_processed_by_key_sets_status(): void
    {
        $this->repo()->recordFirstDelivery('shopee', 'SH1', 'evt-2', '3', []);

        ChannelWebhookInbox::markProcessedByKey('evt-2');

        $row = ChannelWebhookInbox::query()->where('event_key', 'evt-2')->first();
        $this->assertSame(WebhookInboxStatus::PROCESSED, $row->status);
        $this->assertNotNull($row->processed_at);
    }

    public function test_replay_redispatches_stuck_received_events(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'SH1',
            'event_key' => 'stuck-1',
            'event_type' => '3',
            'payload' => ['shop_id' => 'SH1', 'code' => 3, 'data' => ['ordersn' => 'X1']],
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('channel:webhooks-replay', ['--minutes' => 15]);

        Queue::assertPushed(ProcessShopeeWebhook::class, 1);
        $this->assertSame(1, (int) ChannelWebhookInbox::query()->where('event_key', 'stuck-1')->value('attempts'));
    }

    public function test_replay_ignores_recent_and_processed_events(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'recent',
            'event_type' => '3', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::RECEIVED, 'received_at' => now(),
        ]);
        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'done',
            'event_type' => '3', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::PROCESSED, 'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('channel:webhooks-replay', ['--minutes' => 15]);

        Queue::assertNothingPushed();
    }

    public function test_replay_dead_letters_exhausted_events_with_alert(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'maxed',
            'event_type' => '3', 'payload' => ['shop_id' => 'SH1'], 'attempts' => 5,
            'status' => WebhookInboxStatus::RECEIVED, 'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('channel:webhooks-replay', ['--minutes' => 15]);

        Queue::assertNotPushed(ProcessShopeeWebhook::class);
        Queue::assertPushed(AdminAlertJob::class, 1);
        $this->assertSame(
            WebhookInboxStatus::FAILED,
            ChannelWebhookInbox::query()->where('event_key', 'maxed')->value('status'),
        );
    }

    public function test_deferred_intake_event_is_not_dead_lettered_while_intake_is_closed(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => 'deferred-intake',
            'event_type' => '1',
            'payload' => ['type' => 1, 'shop_id' => 'TT1', 'data' => ['order_id' => 'O1']],
            'attempts' => 5,
            'error' => 'ORDER_INTAKE_DEFERRED: Sinkron order ditunda.',
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('channel:webhooks-replay', ['--minutes' => 15]);

        $row = ChannelWebhookInbox::query()->where('event_key', 'deferred-intake')->firstOrFail();
        $this->assertSame(WebhookInboxStatus::RECEIVED, $row->status);
        $this->assertSame(5, $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
        Queue::assertPushed(ProcessTikTokWebhook::class, 1);
        Queue::assertNotPushed(AdminAlertJob::class);
    }

    public function test_dead_letter_preserves_existing_error_and_respects_replay_lease(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'leased-error',
            'event_type' => '29', 'payload' => ['shop_id' => 'SH1'], 'attempts' => 5,
            'error' => 'Shopee API asli: return_not_found',
            'next_attempt_at' => now()->addMinutes(5),
            'status' => WebhookInboxStatus::RECEIVED, 'received_at' => now()->subMinutes(30),
        ]);

        Artisan::call('channel:webhooks-replay', ['--minutes' => 15]);

        $row = ChannelWebhookInbox::query()->where('event_key', 'leased-error')->firstOrFail();
        $this->assertSame(WebhookInboxStatus::RECEIVED, $row->status);
        $this->assertSame('Shopee API asli: return_not_found', $row->error);
        Queue::assertNothingPushed();
    }

    public function test_late_failure_cannot_overwrite_success(): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'already-success',
            'event_type' => '29', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::PROCESSED, 'received_at' => now(),
            'processed_at' => now(),
        ]);

        ChannelWebhookInbox::markFailedByKey('already-success', 'generic late failure');

        $row = ChannelWebhookInbox::query()->where('event_key', 'already-success')->firstOrFail();
        $this->assertSame(WebhookInboxStatus::PROCESSED, $row->status);
        $this->assertNull($row->error);
    }

    public function test_actual_failure_replaces_previous_dispatch_message(): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'actual-error',
            'event_type' => '29', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::RECEIVED,
            'error' => 'Queue dispatch gagal dan akan dicoba ulang',
            'received_at' => now(),
        ]);

        ChannelWebhookInbox::markFailedByKey('actual-error', 'Shopee API asli: return_not_found');

        $row = ChannelWebhookInbox::query()->where('event_key', 'actual-error')->firstOrFail();
        $this->assertSame(WebhookInboxStatus::FAILED, $row->status);
        $this->assertSame('Shopee API asli: return_not_found', $row->error);
    }

    public function test_replay_continues_when_idempotency_cache_is_unavailable(): void
    {
        Queue::fake();
        Cache::shouldReceive('forget')->once()->andThrow(new \RuntimeException('redis-cache unavailable'));

        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'cache-down',
            'event_type' => '3', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::RECEIVED, 'received_at' => now()->subMinutes(30),
        ]);

        $row = ChannelWebhookInbox::query()->where('event_key', 'cache-down')->firstOrFail();

        $this->assertFalse(app(ChannelWebhookService::class)->dispatchInbox($row));
        Queue::assertNothingPushed();
        $row->refresh();
        $this->assertSame(1, $row->attempts);
        $this->assertNotNull($row->next_attempt_at);
        $this->assertStringContainsString('Cache idempotensi tidak tersedia', (string) $row->error);
    }

    public function test_dispatch_is_deferred_before_queue_reaches_redis_oom(): void
    {
        Queue::fake();

        $capacity = Mockery::mock(QueueCapacityReader::class);
        $capacity->shouldReceive('inspect')
            ->once()
            ->with('redis', 'tiktok-orders')
            ->andReturn([
                'allowed' => true,
                'queue_depth' => 10,
                'memory_ratio' => 0.70,
            ]);
        $this->app->instance(QueueCapacityReader::class, $capacity);

        $payload = ['type' => 1, 'shop_id' => 'TT1', 'data' => ['order_id' => 'O1']];
        $row = ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT1',
            'event_key' => ProcessTikTokWebhook::idempotencyKey($payload),
            'event_type' => '1',
            'payload' => $payload,
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now(),
        ]);

        $this->assertFalse(app(ChannelWebhookService::class)->dispatchInbox($row));
        Queue::assertNothingPushed();

        $row->refresh();
        $this->assertSame(1, $row->attempts);
        $this->assertStringStartsWith('QUEUE_CAPACITY_DEFERRED:', (string) $row->error);
        $this->assertNotNull($row->next_attempt_at);
    }

    public function test_tiktok_cancellation_uses_dedicated_cancellation_queue(): void
    {
        $this->assertSame(
            'channel-cancellation',
            ProcessTikTokWebhook::resolveQueueName(['type' => 11]),
        );
    }

    public function test_shopee_final_cancellation_uses_dedicated_cancellation_queue(): void
    {
        $this->assertSame(
            'channel-cancellation',
            ProcessShopeeWebhook::resolveQueueName([
                'code' => 3,
                'data' => ['ordersn' => '2606SHOPEE01', 'status' => 'CANCELLED'],
            ]),
        );

        $this->assertSame(
            'shopee-orders',
            ProcessShopeeWebhook::resolveQueueName([
                'code' => 3,
                'data' => ['ordersn' => '2606SHOPEE02', 'status' => 'READY_TO_SHIP'],
            ]),
        );
    }

    public function test_lazada_final_cancellation_uses_dedicated_cancellation_queue(): void
    {
        $this->assertSame(
            'channel-cancellation',
            ProcessLazadaWebhook::resolveQueueName([
                'message_type' => 0,
                'data' => ['trade_order_id' => '900123', 'order_status' => 'CANCELED'],
            ]),
        );
        $this->assertSame(
            'channel-cancellation',
            ProcessLazadaWebhook::resolveQueueName([
                'message_type' => 14,
                'data' => ['trade_order_id' => '900124', 'status' => 'CANCELLED'],
            ]),
        );
        $this->assertSame(
            'channel-cancellation',
            ProcessLazadaWebhook::resolveQueueName([
                'message_type' => 10,
                'data' => ['trade_order_id' => '900125', 'reverse_status' => 'CANCEL_SUCCESS'],
            ]),
        );
    }

    public function test_lazada_cancellation_request_stays_in_aftersales_queue_until_final(): void
    {
        $this->assertSame(
            'lazada-aftersales',
            ProcessLazadaWebhook::resolveQueueName([
                'message_type' => 10,
                'data' => ['trade_order_id' => '900126', 'reverse_status' => 'CANCEL_INIT'],
            ]),
        );
    }

    public function test_replay_claim_prevents_duplicate_selection_before_lease_expires(): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'shopee', 'shop_id' => 'SH1', 'event_key' => 'claim-once',
            'event_type' => '3', 'payload' => ['shop_id' => 'SH1'],
            'status' => WebhookInboxStatus::RECEIVED, 'received_at' => now()->subMinutes(30),
        ]);

        $first = ChannelWebhookInbox::claimReplayBatch(now()->subMinutes(15), 5, 10);
        $second = ChannelWebhookInbox::claimReplayBatch(now()->subMinutes(15), 5, 10);

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }
}
