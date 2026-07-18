<?php

namespace Modules\Purchase\Enums;

enum PurchaseStatus: string
{
    case DRAFT      = 'DRAFT';
    case APPROVED   = 'APPROVED';
    case ORDERED    = 'ORDERED';
    case PARTIAL    = 'PARTIAL';
    case COMPLETED  = 'COMPLETED';
    case CANCELLED  = 'CANCELLED';

    public function isActive(): bool
    {
        return in_array($this, [self::APPROVED, self::ORDERED, self::PARTIAL], true);
    }
}
