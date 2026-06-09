<?php

namespace Modules\Auth\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $permissions = $this->hasRole('owner')
            ? Permission::pluck('name')
            : $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles->pluck('name'),
            'permissions' => $permissions,
            'nik' => $this->nik,
            'warehouse_id' => $this->warehouse_id,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
