<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

use Modules\Channel\Exceptions\ChannelOrderNotAvailableException;

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
}
