<?php

declare(strict_types=1);

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\RefreshChannelOrderBatchJob;
use Modules\Channel\Jobs\RefreshChannelOrderJob;

final class ChannelOrderRefreshDispatcher
{
    public function __construct(private readonly ChannelOrderRefreshBatchService $batches) {}

    public function dispatch(
        string $channel,
        string $shopId,
        string $orderId,
        ?string $eventKey = null,
        ?int $delaySeconds = null,
    ): void {
        $channel = strtolower(trim($channel));
        $delay = max(0, $delaySeconds ?? (int) config('channel.webhook_order_refresh_delay_seconds', 0));

        if (! in_array($channel, ['shopee', 'tiktok', 'lazada'], true)) {
            RefreshChannelOrderJob::dispatch($channel, $shopId, $orderId, null, $eventKey)
                ->delay(now()->addSeconds($delay));

            return;
        }

        try {
            $this->batches->enqueue($channel, $shopId, $orderId, $eventKey);
            RefreshChannelOrderBatchJob::dispatch($channel, $shopId)
                ->delay(now()->addMilliseconds(max(0, (int) config('channel.order_refresh_batch_window_ms', 150)) + ($delay * 1000)));
        } catch (\Throwable $e) {
            try {
                $this->batches->forget($channel, $shopId, $orderId);
            } catch (\Throwable) {
            }
            Log::warning('Batch channel order refresh fallback to single order job.', [
                'channel' => $channel,
                'shop_id' => $shopId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            RefreshChannelOrderJob::dispatch($channel, $shopId, $orderId, null, $eventKey)
                ->delay(now()->addSeconds($delay));
        }
    }
}
