<?php

declare(strict_types=1);

namespace Modules\Channel\Exceptions;

final class ChannelOrderNotAvailableException extends \RuntimeException
{
    public function __construct(
        public readonly string $channel,
        public readonly string $shopId,
        public readonly string $orderId,
    ) {
        parent::__construct(
            "{$channel} order {$orderId} belum tersedia untuk shop {$shopId} atau respons API kosong."
        );
    }
}
