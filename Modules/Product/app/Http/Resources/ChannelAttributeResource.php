<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelAttributeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_category_id' => $this->channel_category_id,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'is_required' => $this->is_required,
            'is_multiple' => $this->is_multiple,
            'options' => ChannelAttributeOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
