<?php

namespace Modules\Sales\Enums;

enum SalesOrderStatus: string
{
    case PENDING   = 'pending';
    case RESERVED  = 'reserved';
    case PICKED    = 'picked';
    case PACKED    = 'packed';
    case SHIPPED   = 'shipped';
    case CANCELLED = 'cancelled';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::PENDING   => [self::RESERVED, self::CANCELLED],
            self::RESERVED  => [self::PICKED,   self::CANCELLED],
            self::PICKED    => [self::PACKED,   self::CANCELLED],
            self::PACKED    => [self::SHIPPED,  self::CANCELLED],
            self::SHIPPED,
            self::CANCELLED => [],
        }, true);
    }
}
