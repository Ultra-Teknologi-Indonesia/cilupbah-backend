<?php

namespace Modules\Channel\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use Tests\TestCase;

class WebhookFailedRedeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_redelivery_event_failed_memicu_reproses_tapi_received_tetap_duplikat(): void
    {
        $repo = app(ChannelWebhookInboxRepository::class);

        // Pengiriman pertama → RECEIVED + sinyal dispatch (non-null).
        $first = $repo->recordFirstDelivery('shopee', 'S1', 'evt-1', 'order', ['v' => 1]);
        $this->assertNotNull($first);

        // Duplikat saat masih RECEIVED → dianggap duplikat (null, tak reproses).
        $dupWhileReceived = $repo->recordFirstDelivery('shopee', 'S1', 'evt-1', 'order', ['v' => 2]);
        $this->assertNull($dupWhileReceived);

        // Event di-dead-letter (FAILED).
        $first->markFailed('boom');
        $this->assertSame(WebhookInboxStatus::FAILED, $first->fresh()->status);

        // Marketplace me-redeliver event FAILED → beri kesempatan reproses: non-null + reset RECEIVED.
        $redeliver = $repo->recordFirstDelivery('shopee', 'S1', 'evt-1', 'order', ['v' => 3]);
        $this->assertNotNull($redeliver);
        $this->assertSame(WebhookInboxStatus::RECEIVED, $redeliver->fresh()->status);
        $this->assertSame(0, $redeliver->fresh()->attempts);
        $this->assertSame(['v' => 3], $redeliver->fresh()->payload);
    }
}
