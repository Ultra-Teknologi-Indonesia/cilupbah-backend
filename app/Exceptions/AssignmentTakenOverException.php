<?php

namespace App\Exceptions;

use Throwable;

class AssignmentTakenOverException extends UserFacingException
{
    public function __construct(
        string $newAssigneeName,
        ?string $actorName = null,
        ?string $reassignedAt = null,
        ?string $previousAssigneeId = null,
        ?string $newAssigneeId = null,
        ?string $actorId = null,
        ?Throwable $previous = null,
    ) {
        $actorPart = $actorName ? " oleh {$actorName}" : '';
        $timePart = $reassignedAt ? " pada {$reassignedAt}" : '';

        parent::__construct(
            title: 'Tugas dialihkan',
            message: "Tugas kamu sudah dialihkan ke {$newAssigneeName}{$actorPart}{$timePart}. Silakan refresh daftar tugas.",
            status: 409,
            errors: [
                'code' => 'ASSIGNMENT_TAKEN_OVER',
                'previous_assignee' => $previousAssigneeId,
                'new_assignee' => $newAssigneeId,
                'new_assignee_name' => $newAssigneeName,
                'actor' => $actorId,
                'actor_name' => $actorName,
                'reassigned_at' => $reassignedAt,
            ],
            previous: $previous,
        );
    }
}
