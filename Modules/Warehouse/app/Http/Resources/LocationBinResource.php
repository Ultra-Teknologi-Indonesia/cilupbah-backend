<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationBinResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'zone_id' => $this->zone_id,
            'floor_code' => $this->floor_code,
            'row_code' => $this->row_code,
            'column_code' => $this->column_code,
            'bin_code' => $this->bin_code,
            'bin_final_code' => $this->bin_final_code,
            'is_inbound' => (bool) $this->is_inbound,
            'is_stock_acknowledged' => (bool) $this->is_stock_acknowledged,
            'is_large_bin' => (bool) $this->is_large_bin,
            'category' => $this->category,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
