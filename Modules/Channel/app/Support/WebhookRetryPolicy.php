<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

trait WebhookRetryPolicy
{
    public int $tries = 0;

    public function retryUntil(): \DateTimeInterface
    {
        $hours = max(1, (int) config('queue.webhook_retry_window_hours', 24));

        return now()->addHours($hours);
    }
}
