<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
            'parent' => new CategoryResource($this->whenLoaded('parent')),
            'attributes' => AttributeResource::collection($this->whenLoaded('attributes')),
            'channel_categories' => $this->whenLoaded('channelCategories', function () {
                return $this->channelCategories->map(fn ($cc) => [
                    'id' => $cc->id,
                    'channel_id' => $cc->channel_id,
                    'channel_name' => $cc->relationLoaded('channel') && $cc->channel ? $cc->channel->name : null,
                    'external_id' => $cc->external_id,
                    'name' => $cc->name,
                ]);
            }),
        ];
    }
}
