<?php

namespace Modules\Warehouse\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BinMultiSkuRuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'pattern' => $this->pattern,
            'note' => $this->note,
            'is_active' => (bool) $this->is_active,
            'matched_count' => $this->when(
                isset($this->matched_count),
                fn () => (int) $this->matched_count
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
