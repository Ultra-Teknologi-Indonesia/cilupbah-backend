<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
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
}
