<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'use_warehouse_layout' => (bool) $this->use_warehouse_layout,
            'updated_at' => $this->updated_at,
        ];
    }
}
