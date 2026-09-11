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
    ): void {
        if ((int) $pulled > 0) {
            return;
        }

        throw new ChannelOrderNotAvailableException($channel, $shopId, $orderId);
    }

    public static function pullOnce(
        string $channel,
        string $shopId,
        string $orderId,
        Closure $pull,
        int $seconds = 15,
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
                throw $e;
            }
        });

        if (! $pulled) {

            RefreshChannelOrderJob::dispatch($channel, $shopId, $orderId)
                ->delay(now()->addSeconds($seconds));
        }

        return $pulled;
    }
}
