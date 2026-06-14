<?php

namespace Modules\Notification\Jobs;

use Modules\Notification\Services\FcmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public string $userId,
        public string $title,
        public string $body,
        public array $data = [],
    ) {
        $this->onQueue('notifications');
    }

    public function handle(FcmService $fcmService): void
    {
        $fcmService->sendToUser($this->userId, $this->title, $this->body, $this->data);
    }
}
