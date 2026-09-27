<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Channel\Enums\WebhookInboxStatus;
use Modules\Channel\Repositories\QueueHealthRepository;

final class QueueOperationalSnapshotService
{
    public function __construct(
        private readonly QueueHealthRepository $repository,
        private readonly QueueCapacityReader $capacity,
    ) {}

    public function snapshot(): array
    {
        $webhookQueues = [
            config('queue.names.tiktok_webhooks', 'tiktok-webhooks'),
            config('queue.names.shopee_webhooks', 'shopee-webhooks'),
            config('queue.names.lazada_webhooks', 'lazada-webhooks'),
            config('webhook.queue', 'webhooks'),
        ];
        $orderQueues = [
            config('queue.names.orders', 'orders'),
            config('queue.names.shopee_orders', 'shopee-orders'),
            config('queue.names.tiktok_orders', 'tiktok-orders'),
            config('queue.names.lazada_orders', 'lazada-orders'),
            config('queue.names.channel_order_refresh', 'channel-order-refresh'),
        ];
        $counts = Cache::remember(
            'channel-monitor:webhook-status-counts',
            now()->addSeconds(5),
            fn () => $this->repository->webhookStatusCounts(),
        );
        $processed = (int) ($counts[WebhookInboxStatus::PROCESSED->value] ?? 0);
        $received = (int) ($counts[WebhookInboxStatus::RECEIVED->value] ?? 0);
        $failed = (int) ($counts[WebhookInboxStatus::FAILED->value] ?? 0);
        $skipped = (int) ($counts[WebhookInboxStatus::SKIPPED->value] ?? 0);
        $total = $processed + $received + $failed + $skipped;

        return [
            'generated_at' => now()->toIso8601String(),
            'queues' => [
                'webhooks' => $this->capacity->inspect('redis', $webhookQueues),
                'orders' => $this->capacity->inspect('redis', $orderQueues),
            ],
            'webhooks' => compact('processed', 'received', 'failed', 'skipped', 'total') + [
                'processed_last_minute' => Cache::remember(
                    'channel-monitor:processed-last-minute',
                    now()->addSeconds(5),
                    fn (): int => $this->repository->processedWebhookCountSince(now()->subMinute()),
                ),
                'success_rate' => $total === 0 ? 100.0 : round((($processed + $skipped) / $total) * 100, 2),
            ],
            'latest_orders' => Cache::remember(
                'channel-monitor:latest-orders',
                now()->addSeconds(5),
                fn () => $this->repository->latestOrders(3),
            ),
        ];
    }
}
