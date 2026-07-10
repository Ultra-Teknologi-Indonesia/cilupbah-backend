<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isOwner = $this->hasRole('owner');

        $permissions = $isOwner
            ? ($this->all_permission_names ?? collect())
            : $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles->pluck('name'),
            'permissions' => $permissions,

            'direct_permissions' => $isOwner
                ? []
                : $this->getDirectPermissions()->pluck('name'),
            'nik' => $this->nik,
            'warehouse_id' => $this->warehouse_id,
            'locations' => $this->location_tree ?? collect(),
            'avatar_media_id' => $this->avatar_media_id,
            'avatar_url' => $this->avatar_url,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
