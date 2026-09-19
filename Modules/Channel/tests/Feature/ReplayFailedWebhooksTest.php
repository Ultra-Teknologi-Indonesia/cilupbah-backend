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
            ->expectsOutputToContain('dihentikan oleh backpressure');

        Queue::assertNothingPushed();
        $this->assertSame(
            WebhookInboxStatus::RECEIVED,
            ChannelWebhookInbox::query()->value('status'),
        );
    }
}
