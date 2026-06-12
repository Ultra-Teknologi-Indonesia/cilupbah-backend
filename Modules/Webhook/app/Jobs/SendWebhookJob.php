<?php

namespace Modules\Webhook\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Modules\Webhook\Models\WebhookDelivery;

/**
 * Mengirim satu webhook ke URL subscriber (HMAC-SHA256), mencatat hasil ke webhook_deliveries.
 * Retry + backoff; kegagalan TIDAK pernah mengganggu operasi domain (sudah di luar request pemicu).
 */
class SendWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $deliveryId,
    ) {
    }

    /** Backoff bertingkat (detik). */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('subscription')->find($this->deliveryId);
        if (! $delivery || ! $delivery->subscription) {
            return;
        }

        $subscription = $delivery->subscription;
        if (! $subscription->is_active) {
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

        $delivery->increment('attempts');

        $timeout = (int) config('webhook.timeout', 10);
        $response = Http::timeout($timeout)
            ->withBody($json, 'application/json')
            ->withHeaders([
                'X-Cilupbah-Event' => $delivery->event,
                'X-Cilupbah-Event-Id' => $delivery->event_id,
                'X-Cilupbah-Signature' => $signature,
            ])
            ->post($subscription->target_url);

        if ($response->successful()) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_SUCCESS,
                'status_code' => $response->status(),
                'last_error' => null,
                'delivered_at' => now(),
            ]);

            return;
        }

        $delivery->update([
            'status' => WebhookDelivery::STATUS_FAILED,
            'status_code' => $response->status(),
            'last_error' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 500),
        ]);

        // Lempar agar Laravel melakukan retry (sampai $tries) — tetap di luar jalur domain.
        throw new \RuntimeException("Webhook delivery {$delivery->id} gagal (HTTP {$response->status()}).");
    }

    /** Dipanggil setelah retry habis. */
    public function failed(\Throwable $e): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery) {
            $delivery->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ]);
        }
    }
}
