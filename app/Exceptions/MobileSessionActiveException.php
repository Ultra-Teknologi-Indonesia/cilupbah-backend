<?php

namespace App\Exceptions;

use Throwable;

class MobileSessionActiveException extends UserFacingException
{
    public function __construct(
        array $activeParticipants,
        ?Throwable $previous = null,
    ) {
        $names = collect($activeParticipants)->pluck('name')->implode(', ');
        $count = count($activeParticipants);

        parent::__construct(
            title: 'Sesi penerimaan mobile aktif',
            message: "Masih ada {$count} staff yang aktif di sesi penerimaan ({$names}). Admin perlu menekan Selesaikan Penerimaan di web atau menarik peserta dari daftar sebelum mengoreksi atau menghapus.",
            status: 409,
            errors: [
                'code' => 'MOBILE_SESSION_ACTIVE',
                'active_participants' => $activeParticipants,
            ],
            previous: $previous,
        );
    }
}
