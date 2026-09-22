<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\ProcessLazadaWebhook;
use Modules\Channel\Jobs\ProcessShopeeWebhook;
use Modules\Channel\Jobs\ProcessTikTokWebhook;
use Modules\Channel\Jobs\ProcessWooCommerceWebhook;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelWebhookInboxRepository;
use Modules\Sales\Jobs\AdminAlertJob;

final class ChannelWebhookService
{
    public function __construct(
        private readonly ChannelWebhookInboxRepository $repository,
        private readonly QueueCapacityReader $queueCapacity,
    ) {}

    public function recordFirstDelivery(
        string $channel,
        ?string $shopId,
        string $eventKey,
        ?string $eventType,
        array $payload,
    ): ?ChannelWebhookInbox {
        return $this->repository->recordFirstDelivery($channel, $shopId, $eventKey, $eventType, $payload);
    }

    public function isPaused(): bool
    {
        return app(ChannelSyncSettingService::class)->isPaused();
    }

    public function quarantine(
        string $topic,
        string $source,
        string $rawBody,
        string $reason,
        ?array $payload = null,
    ): void {
        $eventKey = 'woocommerce:anomaly:'.md5($source.'|'.$topic.'|'.$rawBody);
        $row = $this->recordFirstDelivery(
            'woocommerce',
            null,
            $eventKey,
            $topic,
            $payload ?? ['_source' => $source, '_raw' => mb_substr($rawBody, 0, 5000)],
        );

        if ($row === null) {
            return;
        }

        $row->markFailed($reason);

        AdminAlertJob::dispatch(
            'WooCommerce webhook tidak dapat diproses',
            $reason,
            ['source' => $source, 'topic' => $topic, 'inbox_id' => $row->id],
        );
    }

    public function dispatchLazada(array $payload, bool $allowWhilePaused = false): bool
    {
        return $this->dispatchSafely(
            'lazada',
            ProcessLazadaWebhook::idempotencyKey($payload),
            ProcessLazadaWebhook::resolveQueueName($payload),
            fn (): mixed => ProcessLazadaWebhook::dispatch($payload),
            $allowWhilePaused,
        );
    }

    public function dispatchShopee(array $payload, bool $allowWhilePaused = false): bool
    {
        return $this->dispatchSafely(
            'shopee',
            ProcessShopeeWebhook::idempotencyKey($payload),
            ProcessShopeeWebhook::resolveQueueName($payload),
            fn (): mixed => ProcessShopeeWebhook::dispatch($payload),
            $allowWhilePaused,
        );
    }

    public function dispatchTikTok(array $payload, bool $allowWhilePaused = false): bool
    {
        return $this->dispatchSafely(
            'tiktok',
            ProcessTikTokWebhook::idempotencyKey($payload),
            ProcessTikTokWebhook::resolveQueueName($payload),
            fn (): mixed => ProcessTikTokWebhook::dispatch($payload)
                ->onQueue(ProcessTikTokWebhook::resolveQueueName($payload)),
            $allowWhilePaused,
        );
    }

    public function dispatchWooCommerce(
        string $shopId,
        string $topic,
        array $payload,
        bool $allowWhilePaused = false,
    ): bool {
        return $this->dispatchSafely(
            'woocommerce',
            ProcessWooCommerceWebhook::idempotencyKey($shopId, $topic, $payload),
            'webhooks',
            fn (): mixed => ProcessWooCommerceWebhook::dispatch(
                $shopId,
                $topic,
                (string) $payload['id'],
                $payload,
            ),
            $allowWhilePaused,
        );
    }

    public function dispatchInbox(ChannelWebhookInbox $row): bool
    {
        $payload = (array) $row->payload;
        $allowWhilePaused = $this->isAcceptedBeforePause($row);

        try {
            Cache::forget((string) $row->event_key);
        } catch (\Throwable $e) {
            Log::warning('Webhook replay ditunda karena cache idempotensi tidak tersedia', [
                'channel' => $row->channel,
                'event_key' => $row->event_key,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            ChannelWebhookInbox::markDispatchFailedByKey(
                (string) $row->event_key,
                'Cache idempotensi tidak tersedia dan akan dicoba ulang: '.$e->getMessage(),
            );

            return false;
        }

        return match (strtolower((string) $row->channel)) {
            'lazada' => $this->dispatchLazada($payload, $allowWhilePaused),
            'shopee' => $this->dispatchShopee($payload, $allowWhilePaused),
            'tiktok' => $this->dispatchTikTok($payload, $allowWhilePaused),
            'woocommerce' => $this->dispatchWooCommerce(
                (string) $row->shop_id,
                (string) $row->event_type,
                $payload,
                $allowWhilePaused,
            ),
            default => false,
        };
    }

    private function isAcceptedBeforePause(ChannelWebhookInbox $row): bool
    {
        $pausedAt = app(ChannelSyncSettingService::class)->current()->paused_at;

        return $pausedAt !== null
            && $row->received_at !== null
            && $row->received_at->lessThanOrEqualTo($pausedAt);
    }

    private function dispatchSafely(
        string $channel,
        string $eventKey,
        string $queue,
        Closure $dispatch,
        bool $allowWhilePaused = false,
    ): bool {
        if ($this->isPaused() && ! $allowWhilePaused) {
            return false;
        }

        if (! $this->canDispatchToQueue($channel, $eventKey, $queue)) {
            return false;
        }

        try {
            $dispatch();
            ChannelWebhookInbox::markDispatchQueuedByKey($eventKey);

            return true;
        } catch (\Throwable $e) {
            Log::error('Webhook queue dispatch gagal; event disimpan untuk retry database', [
                'channel' => $channel,
                'event_key' => $eventKey,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            ChannelWebhookInbox::markDispatchFailedByKey(
                $eventKey,
                'Queue dispatch gagal dan akan dicoba ulang: '.$e->getMessage(),
            );

            return false;
        }
    }

    private function canDispatchToQueue(string $channel, string $eventKey, string $queue): bool
    {
        if (! (bool) config('queue.backpressure.enabled', true)) {
            return true;
        }

        $maxDepth = (int) config('queue.backpressure.webhook_ingress_max_depth', 1000);
        $maxMemoryRatio = (float) config('queue.backpressure.webhook_ingress_max_memory_ratio', 0.70);
        $health = $this->queueCapacity->inspect('redis', $queue);
        $memoryRatio = $health['memory_ratio'] ?? null;

        if ($health['allowed']
            && $health['queue_depth'] < $maxDepth
            && ($memoryRatio === null || $memoryRatio < $maxMemoryRatio)) {
            return true;
        }

        $reason = $health['error'] ?? sprintf(
            'depth=%d/%d, memory=%s/%s',
            (int) ($health['queue_depth'] ?? 0),
            $maxDepth,
            $memoryRatio === null ? 'n/a' : round($memoryRatio * 100, 1).'%',
            round($maxMemoryRatio * 100, 1).'%',
        );

        Log::warning('Webhook queue dispatch ditunda oleh backpressure', [
            'channel' => $channel,
            'event_key' => $eventKey,
            'queue' => $queue,
            'reason' => $reason,
        ]);

        ChannelWebhookInbox::markDispatchFailedByKey(
            $eventKey,
            'QUEUE_CAPACITY_DEFERRED: '.$reason,
        );

        return false;
    }
}
