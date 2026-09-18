<?php

namespace Modules\Outbound\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PicklistListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'picklist_no' => $this->picklist_no,
            'location_id' => $this->location_id,
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'location_name' => $this->location->location_name,
            ] : null),
            'picker_id' => $this->picker_id,
            'picker' => $this->whenLoaded('picker', fn () => $this->picker ? [
                'id' => $this->picker->id,
                'name' => $this->picker->name,
            ] : null),
            'status' => $this->status,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'items_count' => (int) ($this->items_count ?? 0),
            'items_sum_qty_ordered' => (int) ($this->items_sum_qty_ordered ?? 0),
            'items_sum_qty_picked' => (int) ($this->items_sum_qty_picked ?? 0),
            'has_instant' => (bool) $this->has_instant,
        ];
    }
}
