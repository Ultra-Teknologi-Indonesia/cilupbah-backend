<?php

namespace App\Enums;

enum UnassignReasonEnum: string
{
    case SALAH_TAP = 'SALAH_TAP';
    case SHIFT_HABIS = 'SHIFT_HABIS';
    case SAKIT = 'SAKIT';
    case KENDALA_TEKNIS = 'KENDALA_TEKNIS';
    case LAINNYA = 'LAINNYA';
    case FORCE_RESET = 'FORCE_RESET';
    case HANDOVER_BY_LEADER = 'HANDOVER_BY_LEADER';

    public function label(): string
    {
        return match ($this) {
            self::SALAH_TAP => 'Salah tap',
            self::SHIFT_HABIS => 'Shift habis',
            self::SAKIT => 'Sakit / kendala fisik',
            self::KENDALA_TEKNIS => 'Kendala teknis (device/koneksi)',
            self::LAINNYA => 'Lainnya',
            self::FORCE_RESET => 'Reset paksa oleh admin',
            self::HANDOVER_BY_LEADER => 'Dialihkan oleh leader',
        };
    }

    public static function userSelectable(): array
    {
        return [
            self::SALAH_TAP,
            self::SHIFT_HABIS,
            self::SAKIT,
            self::KENDALA_TEKNIS,
            self::LAINNYA,
        ];
    }
}
