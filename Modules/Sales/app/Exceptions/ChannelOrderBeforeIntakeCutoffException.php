<?php

declare(strict_types=1);

namespace Modules\Sales\Exceptions;

use RuntimeException;

final class ChannelOrderBeforeIntakeCutoffException extends RuntimeException
{
    public function __construct(
        public readonly string $source,
        public readonly string $channelShopId,
        public readonly string $channelOrderNo,
        public readonly ?string $transactionDate,
        public readonly string $cutoffAt,
    ) {
        parent::__construct(sprintf(
            'Order channel %s/%s dibuat pada %s, sebelum cutoff intake %s.',
            $source,
            $channelOrderNo,
            $transactionDate ?: 'waktu transaksi tidak tersedia',
            $cutoffAt,
        ));
    }
}
