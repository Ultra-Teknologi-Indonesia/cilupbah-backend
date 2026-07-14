<?php

namespace App\Traits;

use App\Enums\ClientChannelEnum;
use App\Exceptions\AssignmentLockException;
use App\Exceptions\AssignmentTakenOverException;
use App\Exceptions\StaleWriteException;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Kontrak: model yang pakai trait ini harus menyediakan properti untuk kolom
 * assignment. Default: `assigned_to`, `assigned_at`. Override method di bawah
 * kalau nama kolom beda (Picklist pakai `picker_id`, misalnya).
 */
trait EnforcesAssignmentChannel
{
    protected function assignedToColumn(Model $doc): string
    {
        return 'assigned_to';
    }

    protected function assignedAtColumn(Model $doc): string
    {
        return 'assigned_at';
    }

    /**
     * Boolean flag "sudah pernah selesai" — sekali true, guard web unlock.
     * Untuk inbound: `once_received_at`. Untuk putaway/picklist: `completed_at`.
     */
    abstract protected function unlockedOnceColumn(Model $doc): string;

    /**
     * Guard web mutate — throw kalau dokumen sedang di-lock ke MOBILE.
     */
    protected function assertWebCanMutate(Model $doc): void
    {
        $doc->refresh();

        if ($this->isUnlockedByCompletion($doc)) {
            return;
        }

        if ($this->isCancelled($doc)) {
            return;
        }

        $assignedTo = $doc->getAttribute($this->assignedToColumn($doc));

        if ($assignedTo === null) {
            return;
        }

        $name = $this->resolveUserName($assignedTo);
        $assignedAt = $doc->getAttribute($this->assignedAtColumn($doc));

        throw new AssignmentLockException(
            assignedToName: $name,
            assignedToId: (string) $assignedTo,
            assignedAt: $assignedAt?->toDateTimeString(),
        );
    }

    /**
     * Guard mobile mutate — STRICT: actor wajib sama dengan assignee.
     */
    protected function assertMobileCanMutate(Model $doc, string $actorId): void
    {
        $doc->refresh();

        if ($this->isCancelled($doc) || $this->isFinalCompletion($doc)) {
            throw new AssignmentLockException(
                assignedToName: 'system',
                assignedToId: null,
                assignedAt: null,
            );
        }

        $assignedTo = $doc->getAttribute($this->assignedToColumn($doc));

        if ($assignedTo === null) {
            throw new AssignmentLockException(
                assignedToName: 'belum di-assign',
                assignedToId: null,
                assignedAt: null,
            );
        }

        if ((string) $assignedTo !== $actorId) {
            $newAssigneeName = $this->resolveUserName($assignedTo);
            $assignedAt = $doc->getAttribute($this->assignedAtColumn($doc));

            throw new AssignmentTakenOverException(
                newAssigneeName: $newAssigneeName,
                actorName: null,
                reassignedAt: $assignedAt?->toDateTimeString(),
                previousAssigneeId: $actorId,
                newAssigneeId: (string) $assignedTo,
            );
        }
    }

    /**
     * Optimistic concurrency check untuk mutasi web multi-admin.
     */
    protected function assertVersionMatches(Model $doc, ?string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === null) {
            return;
        }

        $current = $doc->getAttribute('updated_version_at')
            ?? $doc->getAttribute('updated_at');

        if ($current === null) {
            return;
        }

        if ($current->toIso8601String() !== $expectedUpdatedAt) {
            throw new StaleWriteException(
                lastEditorName: null,
                lastEditedAt: $current->toDateTimeString(),
            );
        }
    }

    /**
     * Row-level lock helper. Return model yang di-refresh dengan FOR UPDATE lock.
     */
    protected function lockForMutation(Model $doc): Model
    {
        return $doc->newQueryWithoutScopes()
            ->whereKey($doc->getKey())
            ->lockForUpdate()
            ->first();
    }

    protected function isUnlockedByCompletion(Model $doc): bool
    {
        return $doc->getAttribute($this->unlockedOnceColumn($doc)) !== null;
    }

    protected function isCancelled(Model $doc): bool
    {
        return $doc->getAttribute('status') === 'CANCELLED';
    }

    protected function isFinalCompletion(Model $doc): bool
    {
        return in_array($doc->getAttribute('status'), ['COMPLETED', 'CANCELLED'], true);
    }

    protected function resolveUserName($userId): string
    {
        if ($userId === null) {
            return 'seseorang';
        }

        $user = User::find($userId);

        return $user?->name ?? 'pengguna';
    }

    protected function currentChannel(): ClientChannelEnum
    {
        $channel = request()?->attributes->get('client_channel');
        return $channel instanceof ClientChannelEnum ? $channel : ClientChannelEnum::WEB;
    }
}
