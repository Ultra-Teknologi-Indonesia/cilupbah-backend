<?php

namespace Modules\Inventory\Enums;

/**
 * Nilai selaras dengan Postgres check constraint di migrasi
 * create_putaways_table (2026_06_07_100004) — kolom `putaways.status`.
 */
enum PutawayStatus: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED   = 'COMPLETED';
    case CANCELLED   = 'CANCELLED';

    public function isActive(): bool
    {
        return in_array($this, [self::NOT_STARTED, self::IN_PROGRESS], true);
    }
}
