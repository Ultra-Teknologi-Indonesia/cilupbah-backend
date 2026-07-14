<?php

namespace App\Exceptions;

use Throwable;

class AssignmentLockException extends UserFacingException
{
    public function __construct(
        string $assignedToName,
        ?string $assignedToId = null,
        ?string $assignedAt = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            title: 'Dokumen sedang dikerjakan',
            message: "Dokumen sudah di-assign ke {$assignedToName}. Alihkan tugas dulu atau tunggu selesai.",
            status: 409,
            errors: [
                'code' => 'ASSIGNMENT_LOCKED',
                'assigned_to' => $assignedToId,
                'assigned_to_name' => $assignedToName,
                'assigned_at' => $assignedAt,
            ],
            previous: $previous,
        );
    }
}
