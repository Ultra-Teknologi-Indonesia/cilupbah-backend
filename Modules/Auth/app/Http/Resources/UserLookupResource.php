<?php

namespace Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserLookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->id,
            'email' => $this->email,
            'last_login' => optional($this->last_login_at)->toIso8601String(),
            'is_owner' => $this->hasRole('owner'),
        ];
    }
}
