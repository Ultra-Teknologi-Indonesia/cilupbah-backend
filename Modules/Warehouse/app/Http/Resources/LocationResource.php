<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_code' => $this->location_code,
            'location_name' => $this->location_name,
            'location_type' => $this->location_type,
            'address' => $this->address,
            'post_code' => $this->post_code,
            'village_id' => $this->village_id,
            'is_warehouse' => $this->is_warehouse,
            'is_multi_origin' => $this->is_multi_origin,
            'is_active' => $this->is_active,
            'is_fbl' => $this->is_fbl,
            'is_tcb' => $this->is_tcb,
            'is_fbs' => $this->is_fbs,
            'is_pos' => $this->is_pos,
            'village' => $this->whenLoaded('village'),
            'zones' => $this->whenLoaded('zones'),
            'bins' => $this->whenLoaded('bins'),
            'channel_warehouses' => $this->whenLoaded('channelWarehouses'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
