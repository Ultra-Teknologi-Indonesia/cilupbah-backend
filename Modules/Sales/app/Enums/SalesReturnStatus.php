<?php

namespace Modules\Sales\Enums;

/**
 * Nilai selaras dengan SalesReturn::STATUS_* consts di model existing.
 */
enum SalesReturnStatus: string
{
    case PENDING   = 'PENDING';
    case ACCEPTED  = 'ACCEPTED';
    case REJECTED  = 'REJECTED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::REJECTED], true);
    }
}
