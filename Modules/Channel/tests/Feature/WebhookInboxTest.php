<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
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
}
