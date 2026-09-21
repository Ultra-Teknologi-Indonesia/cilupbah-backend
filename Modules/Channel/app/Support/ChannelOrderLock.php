<?php

declare(strict_types=1);

namespace Modules\Channel\Support;

final class ChannelOrderLock
{
    private function __construct()
    {
    }

    public static function forOrder(string $orderId): string
    {
        return 'channel-order-state:'.trim($orderId);
    }
}
