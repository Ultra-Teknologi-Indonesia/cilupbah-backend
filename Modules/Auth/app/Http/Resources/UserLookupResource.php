<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserLookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles ? $this->roles->pluck('name')->toArray() : [],
            'avatar_url' => $this->avatar_url,
            'last_login' => optional($this->last_login_at)->toIso8601String(),
            'is_owner' => $this->hasRole('owner'),
        ];
    }
}

