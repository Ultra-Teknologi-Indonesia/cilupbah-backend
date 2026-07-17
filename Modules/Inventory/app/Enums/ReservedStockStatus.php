<?php

namespace Modules\Inventory\Enums;

/**
 * Selaras dengan Postgres check constraint di create_reserved_stocks_table.
 */
enum ReservedStockStatus: string
{
    case ACTIVE    = 'ACTIVE';
    case EXPIRED   = 'EXPIRED';
    case CANCELLED = 'CANCELLED';
}
