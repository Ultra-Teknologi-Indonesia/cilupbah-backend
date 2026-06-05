<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AdminAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $subject,
        public readonly string $errorMessage,
        public readonly array $context = [],
    ) {
        $this->onQueue(config('queue.names.failed_jobs'));
    }

    public function handle(): void
    {
        // TODO: integrate Slack/email notification channel
        Log::critical("[ADMIN ALERT] {$this->subject}", [
            'error'   => $this->errorMessage,
            'context' => $this->context,
        ]);
    }
}
