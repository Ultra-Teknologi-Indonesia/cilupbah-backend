<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Policy generik untuk aksi assignment (unassign / reset). Feature-specific
 * mapping (inbound/putaway/picking) di-drive dari config warehouse.unassign_roles.
 */
class AssignmentPolicy
{
    /**
     * Boleh unassign (tombol A "Alihkan Tugas") — TAHAN progress.
     * Role primary (kepala gudang, leader) atau fallback (owner, admin).
     */
    public function canUnassign(User $actor, Model $doc, string $feature): bool
    {
        return $this->hasAnyRole($actor, array_merge(
            (array) config('warehouse.unassign_roles.primary', []),
            (array) config("warehouse.unassign_roles.{$feature}", []),
        ));
    }

    /**
     * Boleh force reset (tombol B "Reset & Alihkan") — destructive.
     * Inbound & Putaway: owner + admin. Picking: owner + admin + kepala gudang
     * (karena reversible via unpickItems).
     */
    public function canForceReset(User $actor, Model $doc, string $feature): bool
    {
        $key = $feature === 'picking'
            ? 'warehouse.unassign_roles.reset_picking'
            : 'warehouse.unassign_roles.reset_destructive';

        return $this->hasAnyRole($actor, (array) config($key, []));
    }

    /**
     * Assignee sendiri mundur dari tugas. Wajib reason. TIDAK bisa trigger reset.
     */
    public function canSelfUnassign(User $actor, Model $doc): bool
    {
        $assignedTo = $doc->getAttribute('assigned_to')
            ?? $doc->getAttribute('picker_id');

        return $assignedTo !== null && (string) $assignedTo === (string) $actor->id;
    }

    private function hasAnyRole(User $actor, array $roles): bool
    {
        if (empty($roles)) {
            return false;
        }

        return $actor->hasAnyRole($roles);
    }
}
