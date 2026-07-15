<?php

namespace App\Exceptions;

use Throwable;

class MobileSessionActiveException extends UserFacingException
{
    /**
     * @param array<int, array{user_id: string, name: string}> $activeParticipants
     */
    public function __construct(
        array $activeParticipants,
        ?Throwable $previous = null,
    ) {
        $names = collect($activeParticipants)->pluck('name')->implode(', ');
        $count = count($activeParticipants);

        parent::__construct(
            title: 'Sesi penerimaan mobile aktif',
            message: "Masih ada {$count} staff yang belum menandai Selesai ({$names}). Minta mereka Tandai Selesai di HP, atau tarik dari daftar peserta sebelum mengoreksi.",
            status: 409,
            errors: [
                'code' => 'MOBILE_SESSION_ACTIVE',
                'active_participants' => $activeParticipants,
            ],
            previous: $previous,
        );
    }
}
