<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Modules\Channel\Exceptions\ChannelOrderNotAvailableException;
use Modules\Channel\Jobs\RefreshChannelOrderJob;
use Modules\Sales\Models\SalesOrder;

final class ChannelOrderPullGuard
{
    public static function requirePersisted(
        string $channel,
        string $shopId,
        string $orderId,
        ?int $pulled,
        bool $verifyLocalOrder = false,
    ): void {
        if ((int) $pulled <= 0) {
            throw new ChannelOrderNotAvailableException($channel, $shopId, $orderId);
        }

        if ($verifyLocalOrder && ! SalesOrder::query()
            ->where('source', strtolower($channel))
            ->where('channel_shop_id', $shopId)
            ->where('channel_order_no', $orderId)
            ->exists()) {
            throw new ChannelOrderNotAvailableException($channel, $shopId, $orderId);
        }
    }

    public static function pullOnce(
        string $channel,
        string $shopId,
        string $orderId,
        Closure $pull,
        int $seconds = 15,
        ?string $webhookEventKey = null,
    ): bool {
        $channel = strtolower($channel);
        $key = "{$channel}_pulled_recent:{$shopId}:{$orderId}";

        $pulled = Cache::lock($key.':lock', max(30, $seconds + 5))->block(10, function () use (
            $channel,
            $shopId,
            $orderId,
            $pull,
            $key,
            $seconds,
        ): bool {
            if (! Cache::add($key, true, $seconds)) {
                $persisted = SalesOrder::query()
                    ->where('source', $channel)
                    ->where('channel_order_no', $orderId)
                    ->exists();

                if ($persisted) {
                    return false;
                }

                Cache::forget($key);
                if (! Cache::add($key, true, $seconds)) {
                    return false;
                }
            }

            try {
                self::requirePersisted($channel, $shopId, $orderId, $pull());

                return true;
            } catch (\Throwable $e) {
                Cache::forget($key);

                if (! ChannelErrorClassifier::isRetryable($channel, $e)) {
                    throw $e;
                }

                return false;
            }
        });

        if (! $pulled) {

            RefreshChannelOrderJob::dispatch($channel, $shopId, $orderId, null, $webhookEventKey)
                ->delay(now()->addSeconds(2));
        }

        return $pulled;
    }
}
