<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RackImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'row_no' => $this->row_no,
            'sku' => $this->raw_sku,
            'location' => $this->raw_location,
            'bin' => $this->raw_bin,
            'product_name' => $this->product_name,
            'current_bin' => $this->current_bin,
            'status' => $this->status,
            'message' => $this->message,
        ];
    }
}
