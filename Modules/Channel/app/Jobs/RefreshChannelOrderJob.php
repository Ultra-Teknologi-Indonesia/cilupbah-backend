<?php

declare(strict_types=1);

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Support\ChannelOrderPullGuard;

final class RefreshChannelOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [15, 60];

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(
        public readonly string $channel,
        public readonly string $shopId,
        public readonly string $orderId,
    ) {
        $this->onQueue(self::resolveQueueName($channel));
    }

    public function uniqueId(): string
    {
        return strtolower($this->channel).':'.$this->shopId.':'.$this->orderId;
    }

    public function handle(ChannelOrderRefreshService $orders): void
    {
        $pulled = $orders->refresh($this->channel, $this->shopId, $this->orderId);

        ChannelOrderPullGuard::requirePersisted(
            $this->channel,
            $this->shopId,
            $this->orderId,
            $pulled,
        );

        Log::info('Delayed channel order refresh completed.', [
            'channel' => $this->channel,
            'shop_id' => $this->shopId,
            'order_id' => $this->orderId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('Delayed channel order refresh failed.', [
            'channel' => $this->channel,
            'shop_id' => $this->shopId,
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }

    private static function resolveQueueName(string $channel): string
    {
        return match (strtolower($channel)) {
            'shopee' => (string) config('queue.names.shopee_orders', 'shopee-orders'),
            'tiktok' => (string) config('queue.names.tiktok_orders', 'tiktok-orders'),
            'lazada' => (string) config('queue.names.lazada_orders', 'lazada-orders'),
            'woocommerce' => (string) config('queue.names.webhook_downloads', 'webhook-downloads'),
            default => (string) config('queue.names.channel_sync', 'channel-sync'),
        };
    }
}
