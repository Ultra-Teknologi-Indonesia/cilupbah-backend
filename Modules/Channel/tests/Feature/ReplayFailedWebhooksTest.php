<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\QueueCapacityReader;
use Tests\TestCase;

class ReplayFailedWebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_failed_webhooks_command_redispatches_failed_records(): void
    {
        Queue::fake();

        $shopeeRecord = ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'SHP-1',
            'event_key' => 'shopee:webhook:test1',
            'event_type' => '3',
            'payload' => ['shop_id' => 'SHP-1', 'code' => 3, 'data' => ['ordersn' => 'SN1']],
            'status' => WebhookInboxStatus::FAILED->value,
            'attempts' => 3,
            'error' => 'API timeout',
            'received_at' => now(),
        ]);

        $tiktokRecord = ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT-1',
            'event_key' => 'tiktok:webhook:test2',
            'event_type' => '1',
            'payload' => ['shop_id' => 'TT-1', 'type' => 1, 'data' => ['order_id' => 'TT_ORD_1']],
            'status' => WebhookInboxStatus::FAILED->value,
            'attempts' => 3,
            'error' => 'Rate limit',
            'received_at' => now(),
        ]);

        $this->artisan('webhook:replay-failed')
            ->assertSuccessful()
            ->expectsOutputToContain('Berhasil me-replay 2 webhook.');

        $shopeeRecord->refresh();
        $tiktokRecord->refresh();

        $this->assertEquals(WebhookInboxStatus::RECEIVED, $shopeeRecord->status);
        $this->assertEquals(WebhookInboxStatus::RECEIVED, $tiktokRecord->status);

        Queue::assertPushed(ProcessShopeeWebhook::class);
        Queue::assertPushed(ProcessTikTokWebhook::class);
    }

    public function test_replay_does_not_add_work_when_operational_queues_are_at_capacity(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT-1',
            'event_key' => 'tiktok:webhook:capacity-test',
            'event_type' => '1',
            'payload' => ['shop_id' => 'TT-1', 'type' => 1, 'data' => ['order_id' => 'TT_CAPACITY']],
            'status' => WebhookInboxStatus::RECEIVED->value,
            'attempts' => 0,
            'received_at' => now()->subMinutes(30),
        ]);

        $capacity = Mockery::mock(QueueCapacityReader::class);
        $capacity->shouldReceive('inspect')->once()->andReturn([
            'allowed' => true,
            'queue_connection' => 'redis',
            'redis_connection' => 'default',
            'queue_depth' => 500,
            'ready' => 500,
            'reserved' => 0,
            'delayed' => 0,
            'memory_used_bytes' => 100,
            'memory_max_bytes' => 1000,
            'memory_ratio' => 0.10,
        ]);
        $this->app->instance(QueueCapacityReader::class, $capacity);

        $this->artisan('channel:webhooks-replay')
            ->assertSuccessful()
            ->expectsOutputToContain('dispatch ulang 0');

        Queue::assertNothingPushed();
        $this->assertSame(
            WebhookInboxStatus::RECEIVED,
            ChannelWebhookInbox::query()->value('status'),
        );
    }

    public function test_replay_keeps_retrying_infrastructure_failures_after_five_attempts(): void
    {
        Queue::fake();

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'shop_id' => 'TT-1',
            'event_key' => 'tiktok:webhook:redis-oom-retry',
            'event_type' => '1',
            'payload' => ['type' => 1, 'shop_id' => 'TT-1', 'data' => ['order_id' => 'TT_RETRY']],
            'status' => WebhookInboxStatus::RECEIVED,
            'attempts' => 5,
            'error' => "Queue dispatch gagal dan akan dicoba ulang: OOM command not allowed when used memory > 'maxmemory'.",
            'received_at' => now()->subMinutes(30),
            'next_attempt_at' => now()->subMinute(),
        ]);

        $this->artisan('channel:webhooks-replay', ['--minutes' => 15])
            ->assertSuccessful();

        $row = ChannelWebhookInbox::query()->where('event_key', 'tiktok:webhook:redis-oom-retry')->firstOrFail();
        $this->assertSame(WebhookInboxStatus::RECEIVED, $row->status);
        $this->assertSame(6, $row->attempts);
        Queue::assertPushed(ProcessTikTokWebhook::class, 1);
    }

    public function test_replay_checks_the_destination_lane_instead_of_blocking_all_channels(): void
    {
        Queue::fake();
        ChannelWebhookInbox::create([
            'channel' => 'tiktok', 'shop_id' => 'TT-1',
            'event_key' => 'tiktok:webhook:independent-lane', 'event_type' => '1',
            'payload' => ['type' => 1, 'shop_id' => 'TT-1', 'data' => ['order_id' => 'TT_LANE']],
            'status' => WebhookInboxStatus::RECEIVED,
            'received_at' => now()->subMinutes(30),
        ]);
        $capacity = Mockery::mock(QueueCapacityReader::class);
        $capacity->shouldReceive('inspect')->andReturnUsing(function ($connection, $queues): array {

            return [
                'allowed' => true, 'queue_depth' => is_array($queues) ? 1000 : 0,
                'memory_ratio' => 0.1,
            ];
        });
        $this->app->instance(QueueCapacityReader::class, $capacity);

        $this->artisan('channel:webhooks-replay')->assertSuccessful();

        Queue::assertPushed(ProcessTikTokWebhook::class, 1);
    }

    public function test_replay_cannot_spend_a_cached_capacity_slot_twice(): void
    {
        Queue::fake();
        foreach ([1, 2] as $index) {
            ChannelWebhookInbox::create([
                'channel' => 'tiktok', 'shop_id' => 'TT-1',
                'event_key' => 'capacity-slot-'.$index, 'event_type' => '1',
                'payload' => ['type' => 1, 'shop_id' => 'TT-1', 'data' => ['order_id' => 'SLOT-'.$index]],
                'status' => WebhookInboxStatus::RECEIVED,
                'received_at' => now()->subMinutes(30),
            ]);
        }
        $capacity = Mockery::mock(QueueCapacityReader::class);
        $capacity->shouldReceive('inspect')->andReturn([
            'allowed' => true, 'queue_depth' => 499, 'memory_ratio' => 0.1,
        ]);
        $this->app->instance(QueueCapacityReader::class, $capacity);

        $this->artisan('channel:webhooks-replay')->assertSuccessful();

        Queue::assertPushed(ProcessTikTokWebhook::class, 1);
        $this->assertSame(1, ChannelWebhookInbox::where('error', 'like', 'QUEUE_CAPACITY_DEFERRED:%')->count());
    }
}
