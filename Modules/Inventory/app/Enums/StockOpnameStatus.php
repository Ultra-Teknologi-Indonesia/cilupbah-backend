<?php

namespace Modules\Inventory\Enums;

/**
 * Selaras dengan Postgres check constraint di create_stock_opnames_table.
 */
enum StockOpnameStatus: string
{
    case DRAFT       = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case FINALIZED   = 'FINALIZED';
    case CANCELLED   = 'CANCELLED';
}
