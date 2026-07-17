<?php

namespace Modules\Inventory\Enums;

/**
 * Selaras dengan Postgres check constraint di create_stock_adjustments_table.
 */
enum StockAdjustmentStatus: string
{
    case DRAFT     = 'DRAFT';
    case APPROVED  = 'APPROVED';
    case CANCELLED = 'CANCELLED';
}
