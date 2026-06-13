<?php

namespace Modules\Webhook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Modules\Webhook\Repositories\WebhookDeliveryRepository;
use Modules\Webhook\Repositories\WebhookSubscriptionRepository;
use Modules\Webhook\Support\WebhookUrlGuard;

class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $deliveryId,
    ) {
    }

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(
        WebhookDeliveryRepository $deliveries,
        WebhookSubscriptionRepository $subscriptions,
    ): void {
        $delivery = $deliveries->findWithSubscription($this->deliveryId);
        if (! $delivery || ! $delivery->subscription) {
            return;
        }

        $subscription = $delivery->subscription;
        if (! $subscription->is_active) {
            return;
        }

        if (! (new WebhookUrlGuard())->isSafe($subscription->target_url)) {
            $deliveries->markFailed($delivery, null, 'Target URL diblokir (privat/non-https).');

            return;
        }

        $body = [
            'event' => $delivery->event,
            'event_id' => $delivery->event_id,
            'data' => $delivery->payload,
            'sent_at' => now()->toIso8601String(),
        ];
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = hash_hmac('sha256', $json, $subscription->secret);

        $deliveries->incrementAttempts($delivery);

        $timeout = (int) config('webhook.timeout', 10);
        $response = Http::timeout($timeout)
            ->withBody($json, 'application/json')
            ->withHeaders([
                'X-Cilupbah-Event' => $delivery->event,
                'X-Cilupbah-Event-Id' => $delivery->event_id,
                'X-Cilupbah-Signature' => $signature,
            ])
            ->post($subscription->target_url);

        if ($response->successful() || $response->redirect()) {
            $deliveries->markSuccess($delivery, $response->status());
            $subscriptions->resetFailures($subscription);

            return;
        }

        $deliveries->markFailed($delivery, $response->status(), 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500));

        throw new \RuntimeException("Webhook delivery {$delivery->id} gagal (HTTP {$response->status()}).");
    }

    public function failed(\Throwable $e): void
    {
        $deliveries = app(WebhookDeliveryRepository::class);
        $delivery = $deliveries->find($this->deliveryId);
        if (! $delivery) {
            return;
        }

        $deliveries->markFailed($delivery, $delivery->status_code, $e->getMessage());

        app(WebhookSubscriptionRepository::class)->registerFailureAndMaybeDisable(
            $delivery->subscription_id,
            (int) config('webhook.max_consecutive_failures', 15),
        );
    }
}
