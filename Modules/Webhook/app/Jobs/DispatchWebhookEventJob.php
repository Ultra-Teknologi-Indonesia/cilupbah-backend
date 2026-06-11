<?php

namespace Modules\Webhook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Modules\Webhook\Models\WebhookDelivery;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;

/**
 * Berjalan SETELAH commit (lihat dispatcher ->afterCommit()).
 * Fan-out: cari subscriber aktif untuk event → buat delivery (pending) → antrekan SendWebhookJob per subscriber.
 * Semua query di sini terjadi di LUAR transaksi/lock domain.
 */
class DispatchWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $event,
        public array $payload,
    ) {
    }

    public function handle(WebhookSubscriptionRepository $repository): void
    {
        $subscriptions = $repository->activeForEvent($this->event);
        if ($subscriptions->isEmpty()) {
            return;
        }

        $eventId = (string) Str::uuid7();

        foreach ($subscriptions as $subscription) {
            $delivery = WebhookDelivery::create([
                'subscription_id' => $subscription->id,
                'event' => $this->event,
                'event_id' => $eventId,
                'payload' => $this->payload,
                'status' => WebhookDelivery::STATUS_PENDING,
            ]);

            SendWebhookJob::dispatch($delivery->id)
                ->onQueue(config('webhook.queue', 'webhooks'));
        }
    }
}
