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

    case UNPAID                       = 'UNPAID';
    case READY                        = 'READY';
    case AWAITING_BUYER_CONFIRMATION  = 'AWAITING_BUYER_CONFIRMATION';

    public function canonical(): self
    {
        return match ($this) {
            self::UNPAID                      => self::PENDING,
            self::READY                       => self::PACKED,
            self::AWAITING_BUYER_CONFIRMATION => self::RESERVED,
            default                           => $this,
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->canonical(), match ($this->canonical()) {
            self::PENDING   => [self::RESERVED, self::CANCELLED],
            self::RESERVED  => [self::PICKED,   self::CANCELLED],
            self::PICKED    => [self::PACKED,   self::CANCELLED],
            self::PACKED    => [self::SHIPPED,  self::CANCELLED],
            self::SHIPPED,
            self::CANCELLED => [],
            default         => [],
        }, true);
    }
}
