<?php

declare(strict_types=1);

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelOrderRefreshService;
use Modules\Channel\Support\ChannelOrderLock;
use Modules\Channel\Support\ChannelOrderPullGuard;
use Modules\Channel\Support\WebhookFailureHandler;

final class RefreshChannelOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [2, 5, 15, 30, 60, 120];

    public int $timeout = 120;

    public int $tries = 8;

    public int $maxExceptions = 8;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $channel,
        public readonly string $shopId,
        public readonly string $orderId,
        ?string $queue = null,
        public readonly ?string $webhookEventKey = null,
    ) {
        $this->onQueue($queue ?: self::resolveQueueName($channel));
    }

    public function uniqueId(): string
    {
        return strtolower($this->channel).':'.$this->shopId.':'.$this->orderId;
    }

    public function middleware(): array
    {
        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping(ChannelOrderLock::forOrder($this->orderId)))
                ->releaseAfter(2)
                ->expireAfter(180),
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(ChannelOrderRefreshService $orders): void
    {
        $pulled = $orders->refresh($this->channel, $this->shopId, $this->orderId);

        ChannelOrderPullGuard::requirePersisted(
            $this->channel,
            $this->shopId,
            $this->orderId,
            $pulled,
            verifyLocalOrder: true,
        );

        Log::info('Delayed channel order refresh completed.', [
            'channel' => $this->channel,
            'shop_id' => $this->shopId,
            'order_id' => $this->orderId,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        if ($this->webhookEventKey !== null && $this->webhookEventKey !== '') {
            WebhookFailureHandler::recordDownstreamFailure(
                $this->channel,
                $this->webhookEventKey,
                [
                    'shop_id' => $this->shopId,
                    'order_id' => $this->orderId,
                    'queue' => $this->queue,
                ],
                $e,
                $this->job?->uuid(),
            );
        }

        Log::warning('Delayed channel order refresh failed.', [
            'channel' => $this->channel,
            'shop_id' => $this->shopId,
            'order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }

    private static function resolveQueueName(string $channel): string
    {
        return (string) config('queue.names.channel_order_refresh', 'channel-order-refresh');
    }
}
