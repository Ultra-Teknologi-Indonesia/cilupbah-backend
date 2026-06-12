<?php

namespace Modules\Channel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'shops' => ChannelShopResource::collection($this->whenLoaded('shops')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
