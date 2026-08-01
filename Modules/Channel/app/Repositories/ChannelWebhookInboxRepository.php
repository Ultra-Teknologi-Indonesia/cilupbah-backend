<?php

namespace Modules\Channel\Repositories;

use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Models\ChannelWebhookInbox;
use Ramsey\Uuid\Uuid;

class ChannelWebhookInboxRepository
{
    public function recordFirstDelivery(
        string $channel,
        ?string $shopId,
        string $eventKey,
        ?string $eventType,
        array $payload,
    ): ?ChannelWebhookInbox {
        $id = Uuid::uuid7()->toString();
        $now = now();

        $inserted = ChannelWebhookInbox::query()->insertOrIgnore([
            'id' => $id,
            'channel' => $channel,
            'shop_id' => $shopId,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'payload' => json_encode($payload),
            'status' => WebhookInboxStatus::RECEIVED->value,
            'attempts' => 0,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 0) {
            return null;
        }

        return ChannelWebhookInbox::query()->find($id);
    }

    public function find(string $id): ?ChannelWebhookInbox
    {
        return ChannelWebhookInbox::query()->find($id);
    }
}
