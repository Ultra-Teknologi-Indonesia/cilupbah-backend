<?php

declare(strict_types=1);

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelOrderRefreshBatchService;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Support\ChannelOrderLock;

final class RefreshChannelOrderBatchJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [2, 5, 15, 30, 60, 120];

    public int $timeout = 180;

    public int $tries = 8;

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $channel,
        public readonly string $shopId,
    ) {
        $this->onQueue((string) config('queue.names.channel_order_refresh', 'channel-order-refresh'));
    }

    public function uniqueId(): string
    {
        return strtolower($this->channel).':'.$this->shopId;
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping(ChannelOrderLock::forShop($this->channel, $this->shopId)))
                ->releaseAfter(2)
                ->expireAfter(240),
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(
        ChannelOrderRefreshBatchService $batches,
        ChannelOrderRefreshService $orders,
        ChannelSyncSettingService $settings,
    ): void {
        $items = $batches->pop(
            $this->channel,
            $this->shopId,
            max(1, min(50, (int) config('channel.order_refresh_batch_size', 50))),
        );

        if ($items === []) {
            return;
        }

        $allowWhilePaused = collect($items)->contains(
            static fn (array $item): bool => filled($item['event_key'] ?? null),
        );

        if ($settings->isPaused() && ! $allowWhilePaused) {
            foreach ($items as $orderId => $item) {
                RefreshChannelOrderJob::dispatch(
                    $this->channel,
                    $this->shopId,
                    (string) $orderId,
                    null,
                    $item['event_key'] ?? null,
                )->delay(now()->addSeconds(5));
            }
            $batches->ack($this->channel, $this->shopId, array_keys($items));
        } else {
            try {
                $result = $orders->refreshMany(
                    $this->channel,
                    $this->shopId,
                    array_keys($items),
                    $allowWhilePaused,
                );
            } catch (\Throwable $e) {
                foreach ($items as $orderId => $item) {
                    RefreshChannelOrderJob::dispatch(
                        $this->channel,
                        $this->shopId,
                        (string) $orderId,
                        null,
                        $item['event_key'] ?? null,
                    )->delay(now()->addSeconds(2));
                }
                $batches->ack($this->channel, $this->shopId, array_keys($items));

                Log::warning('Channel order batch refresh fell back to individual jobs.', [
                    'channel' => $this->channel,
                    'shop_id' => $this->shopId,
                    'requested' => count($items),
                    'error' => $e->getMessage(),
                ]);

                if ($batches->hasPending($this->channel, $this->shopId)) {
                    self::dispatch($this->channel, $this->shopId);
                }

                return;
            }

            foreach ($result['failed'] as $orderId) {
                $item = $items[$orderId] ?? [];
                RefreshChannelOrderJob::dispatch(
                    $this->channel,
                    $this->shopId,
                    (string) $orderId,
                    null,
                    $item['event_key'] ?? null,
                )->delay(now()->addSeconds(2));
            }

            $batches->ack($this->channel, $this->shopId, array_keys($items));

            Log::info('Channel order batch refresh completed.', [
                'channel' => $this->channel,
                'shop_id' => $this->shopId,
                'requested' => count($items),
                'pulled' => $result['pulled'],
                'failed' => count($result['failed']),
            ]);
        }

        if ($batches->hasPending($this->channel, $this->shopId)) {
            self::dispatch($this->channel, $this->shopId);
        }
    }
}
