<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_id' => $this->channel_id,
            'external_id' => $this->external_id,
            'parent_external_id' => $this->parent_external_id,
            'name' => $this->name,
            'is_leaf' => $this->is_leaf,
            'children' => ChannelCategoryResource::collection($this->whenLoaded('children')),
            'parent' => new ChannelCategoryResource($this->whenLoaded('parent')),
        ];
    }
}
