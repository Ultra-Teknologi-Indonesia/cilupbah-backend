<?php

namespace Modules\Inventory\Enums;

enum StockAdjustmentStatus: string
{
    case DRAFT     = 'DRAFT';
    case APPROVED  = 'APPROVED';
    case CANCELLED = 'CANCELLED';
}
