<?php

namespace Modules\Purchase\Enums;

enum PurchaseBillStatus: string
{
    case DRAFT     = 'DRAFT';
    case UNPAID    = 'UNPAID';
    case PARTIAL   = 'PARTIAL';
    case PAID      = 'PAID';
    case CANCELLED = 'CANCELLED';
}
