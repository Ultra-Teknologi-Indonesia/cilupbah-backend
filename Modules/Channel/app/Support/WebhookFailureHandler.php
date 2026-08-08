<?php

namespace Modules\Channel\Support;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Sales\Jobs\AdminAlertJob;

class WebhookFailureHandler
{
    public static function record(
        string $channel,
        string $eventKey,
        array $context,
        \Throwable $e,
    ): void {
        ChannelWebhookInbox::markFailedByKey($eventKey, $e->getMessage());

        Log::error("Webhook {$channel} gagal permanen — ditandai FAILED di inbox.", [
            'event_key' => $eventKey,
            'error' => $e->getMessage(),
            'context' => $context,
        ]);

        AdminAlertJob::dispatch(
            "Webhook {$channel} gagal permanen",
            $e->getMessage(),
            $context + ['channel' => $channel, 'event_key' => $eventKey],
        );
    }
}
