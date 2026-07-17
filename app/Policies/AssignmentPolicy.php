<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AssignmentPolicy
{

    public function canUnassign(User $actor, Model $doc, string $feature): bool
    {
        return $this->hasAnyRole($actor, array_merge(
            (array) config('warehouse.unassign_roles.primary', []),
            (array) config("warehouse.unassign_roles.{$feature}", []),
        ));
    }

    public function canForceReset(User $actor, Model $doc, string $feature): bool
    {
        $key = $feature === 'picking'
            ? 'warehouse.unassign_roles.reset_picking'
            : 'warehouse.unassign_roles.reset_destructive';

        return $this->hasAnyRole($actor, (array) config($key, []));
    }

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
