<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'bin_id' => $this->bin_id,
            'bin_code' => $this->whenLoaded('bin', fn () => $this->bin?->bin_final_code),
            'floor_code' => $this->whenLoaded('bin', fn () => $this->bin?->floor_code),
            'row_code' => $this->whenLoaded('bin', fn () => $this->bin?->row_code),
            'column_code' => $this->whenLoaded('bin', fn () => $this->bin?->column_code),
            'zone_id' => $this->whenLoaded('bin', fn () => $this->bin?->zone_id),
            'zone_code' => $this->whenLoaded('bin', fn () => $this->bin?->zone?->zone_code),
            'zone_name' => $this->whenLoaded('bin', fn () => $this->bin?->zone?->zone_name),
            'batch_no' => $this->batch_no,
            'serial_no' => $this->serial_no,
            'expired_date' => $this->expired_date,
            'on_hand' => (int) $this->on_hand,
            'on_order' => (int) $this->on_order,
            'reserved' => (int) $this->reserved,
            'available' => (int) $this->available,
            'avg_cost' => (float) $this->avg_cost,
        ];
    }
}
