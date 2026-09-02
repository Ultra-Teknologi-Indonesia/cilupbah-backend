<?php

namespace Modules\Webhook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Webhook\Repositories\WebhookDeliveryRepository;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

class DispatchWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30, 120];

    public function __construct(
        public string $event,
        public array $payload,
        public string $eventId,
    ) {
        $this->onQueue(config('webhook.queue', 'webhooks'));
    }

    public function handle(
        WebhookSubscriptionRepository $subscriptions,
        WebhookDeliveryRepository $deliveries,
    ): void {
        $activeSubscriptions = $subscriptions->activeForEvent($this->event);
        if ($activeSubscriptions->isEmpty()) {
            return;
        }

        foreach ($activeSubscriptions as $subscription) {
            $delivery = $deliveries->firstOrCreateForEvent(
                $subscription->id,
                $this->event,
                $this->eventId,
                $this->payload,
            );

            if ($delivery->wasRecentlyCreated) {
                SendWebhookJob::dispatch($delivery->id)
                    ->onQueue(config('webhook.queue', 'webhooks'));
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('DispatchWebhookEventJob gagal permanen — fan-out event hilang', [
            'event' => $this->event,
            'event_id' => $this->eventId,
            'error' => $e->getMessage(),
        ]);
    }
}
