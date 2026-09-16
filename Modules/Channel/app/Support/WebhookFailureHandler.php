<?php

namespace Modules\Channel\Support;

use App\Support\QueueFailureRecorder;
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
        ?string $jobUuid = null,
    ): void {
        $message = app(QueueFailureRecorder::class)->messageForJob($jobUuid, $e);

        ChannelWebhookInbox::markFailedByKey($eventKey, $message);

        Log::error("Webhook {$channel} gagal permanen — ditandai FAILED di inbox.", [
            'event_key' => $eventKey,
            'exception' => $e::class,
            'error' => $message,
            'context' => $context,
        ]);

        AdminAlertJob::dispatch(
            "Webhook {$channel} gagal permanen",
            $message,
            $context + ['channel' => $channel, 'event_key' => $eventKey],
        );
    }
}
