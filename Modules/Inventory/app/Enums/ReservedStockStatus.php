<?php

namespace Modules\Inventory\Enums;

enum ReservedStockStatus: string
{
    case ACTIVE    = 'ACTIVE';
    case EXPIRED   = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
}
