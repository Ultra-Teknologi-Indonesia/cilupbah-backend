<?php

declare(strict_types=1);

namespace Modules\Channel\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelWebhookAuditService;
use Modules\Sales\Models\SalesOrder;
use Tests\TestCase;

class ChannelWebhookAuditServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_note_contains_channel_event_time_and_is_idempotent(): void
    {
        $order = SalesOrder::create([
            'salesorder_no' => 'TT-AUDIT-001',
            'channel_order_no' => 'AUDIT-001',
            'source' => 'tiktok',
            'status' => 'reserved',
            'seller_note' => 'Catatan operator',
            'is_canceled' => false,
        ]);

        $eventKey = 'tiktok_webhook:nid:audit-001';
        $receivedAt = CarbonImmutable::create(2026, 8, 25, 5, 19, 39, 'UTC');
        $payload = [
            'type' => 8,
            'data' => [
                'order_id' => 'AUDIT-001',
                'order_status' => 'AWAITING_COLLECTION',
            ],
        ];

        ChannelWebhookInbox::create([
            'channel' => 'tiktok',
            'event_key' => $eventKey,
            'event_type' => 'type:8',
            'payload' => $payload,
            'status' => WebhookInboxStatus::PROCESSED,
            'received_at' => $receivedAt,
        ]);

        $service = app(ChannelWebhookAuditService::class);
        $this->assertSame(1, $service->recordFromInbox('tiktok', $eventKey, $payload));
        $this->assertSame(0, $service->recordFromInbox('tiktok', $eventKey, $payload));

        $note = (string) $order->fresh()->seller_note;

        $this->assertStringContainsString('Catatan operator', $note);
        $this->assertStringContainsString('Webhook channel=TIKTOK', $note);
        $this->assertStringContainsString('event=type:8', $note);
        $this->assertStringContainsString('status=AWAITING_COLLECTION', $note);
        $this->assertStringContainsString('diterima=25-08-2026 12:19:39 WIB', $note);
        $this->assertSame(1, substr_count($note, "Webhook event_key={$eventKey}"));
    }
}
