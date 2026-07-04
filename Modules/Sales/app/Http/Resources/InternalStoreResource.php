<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternalStoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $logoUrl   = $this->getFirstMediaUrl('logo');
        $thumbUrl  = $this->getFirstMediaUrl('logo', 'thumb');

        return [
            'id'         => $this->id,
            'code'       => $this->code,
            'name'       => $this->name,
            'is_active'  => (bool) $this->is_active,
            'logo_url'   => $logoUrl !== '' ? $logoUrl : null,
            'logo_thumb' => $thumbUrl !== '' ? $thumbUrl : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
