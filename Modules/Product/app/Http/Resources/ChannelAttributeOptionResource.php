<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelAttributeOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_attribute_id' => $this->channel_attribute_id,
            'external_id' => $this->external_id,
            'name' => $this->name,
        ];
    }
}
