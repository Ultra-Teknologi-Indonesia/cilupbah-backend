<?php

namespace Modules\Inbound\Enums;

/**
 * Nilai default DB `inbounds.status` = 'DRAFT' (migrasi create_inbounds_table).
 * Kolom tanpa DB constraint di schema asal — enum + check constraint di
 * migrasi turunan menegakkan set ini.
 */
enum InboundStatus: string
{
    case DRAFT       = 'DRAFT';
    case IN_PROGRESS = 'IN_PROGRESS';
    case COMPLETED   = 'COMPLETED';
    case CANCELLED   = 'CANCELLED';

    public function isActive(): bool
    {
        return in_array($this, [self::DRAFT, self::IN_PROGRESS], true);
    }
}
