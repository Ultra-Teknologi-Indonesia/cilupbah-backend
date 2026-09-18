<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PicklistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'picklist_no' => $this->picklist_no,
            'location_id' => $this->location_id,
            'location' => $this->relationLoaded('location') && $this->location ? [
                'id' => $this->location->id,
                'location_name' => $this->location->location_name,
                'location_code' => $this->location->location_code,
            ] : null,
            'picker_id' => $this->picker_id,
            'picker' => $this->relationLoaded('picker') && $this->picker ? [
                'id' => $this->picker->id,
                'name' => $this->picker->name,
                'email' => $this->picker->email,
            ] : null,
            'status' => $this->status,
            'assigned_at' => $this->assigned_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => PicklistItemResource::collection($this->items)),
        ];
    }
}
