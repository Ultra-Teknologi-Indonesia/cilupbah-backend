<?php

namespace Modules\Inventory\Enums;

enum BinTransferStatus: string
{
    case DRAFT      = 'DRAFT';
    case APPROVED   = 'APPROVED';
    case IN_TRANSIT = 'IN_TRANSIT';
    case RECEIVED   = 'RECEIVED';
    case CANCELLED  = 'CANCELLED';

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::DRAFT      => [self::APPROVED, self::CANCELLED],
            self::APPROVED   => [self::IN_TRANSIT, self::DRAFT, self::CANCELLED],
            self::IN_TRANSIT => [self::RECEIVED, self::APPROVED],
            self::RECEIVED,
            self::CANCELLED  => [],
        }, true);
    }
}
