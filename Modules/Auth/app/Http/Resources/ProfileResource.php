<?php

namespace Modules\Auth\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProfileResource extends JsonResource
{
    protected static ?Collection $allPermissionNames = null;

    public function toArray(Request $request): array
    {
        $permissions = $this->hasRole('owner')
            ? static::$allPermissionNames ??= Permission::pluck('name')
            : $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles->pluck('name'),
            'permissions' => $permissions,
            'nik' => $this->nik,
            'warehouse_id' => $this->warehouse_id,
            'avatar_media_id' => $this->avatar_media_id,
            'avatar_url' => $this->avatar_url,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
