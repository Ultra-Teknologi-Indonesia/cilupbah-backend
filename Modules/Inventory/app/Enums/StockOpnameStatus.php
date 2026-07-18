<?php

namespace Modules\Inventory\Enums;

enum StockOpnameStatus: string
{
    case DRAFT       = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case FINALIZED   = 'FINALIZED';
    case CANCELLED   = 'CANCELLED';
}
