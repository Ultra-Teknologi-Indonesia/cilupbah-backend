<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use Tests\TestCase;

class WebhookReturnReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_return_reference_is_extracted_on_first_delivery(): void
    {
        $row = app(ChannelWebhookInboxRepository::class)->recordFirstDelivery(
            'tiktok',
            'SHOP-1',
            'return-event-1',
            '2',
            ['data' => ['reverse_order_id' => 'RETURN-123', 'reverse_status' => 'REQUESTED']],
        );

        $this->assertSame('RETURN-123', $row?->channel_return_id);
    }

    public function test_backfill_command_is_idempotent_and_supports_dry_run(): void
    {
        ChannelWebhookInbox::create([
            'channel' => 'shopee',
            'shop_id' => 'SHOP-1',
            'event_key' => 'return-event-2',
            'event_type' => '29',
            'payload' => ['data' => ['return_sn' => 'RETURN-456']],
            'received_at' => now(),
        ]);

        $this->artisan('channel:webhooks-backfill-return-references', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseMissing('channel_webhook_inbox', [
            'event_key' => 'return-event-2',
            'channel_return_id' => 'RETURN-456',
        ]);

        $this->artisan('channel:webhooks-backfill-return-references')->assertExitCode(0);
        $this->artisan('channel:webhooks-backfill-return-references')->assertExitCode(0);

        $this->assertDatabaseHas('channel_webhook_inbox', [
            'event_key' => 'return-event-2',
            'channel_return_id' => 'RETURN-456',
        ]);
    }
}
