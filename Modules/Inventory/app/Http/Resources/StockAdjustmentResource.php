<?php

namespace Modules\Inventory\Http\Resources;

use App\Support\ActorName;
use Illuminate\Http\Resources\Json\JsonResource;

class StockAdjustmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'adjustment_no' => $this->adjustment_no,
            'transaction_date' => $this->transaction_date,
            'location_id' => $this->location_id,
            'is_beginning_balance' => (bool) $this->is_beginning_balance,
            'notes' => $this->notes,
            'created_by' => ActorName::resolve($this->created_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'location_name' => $this->location->location_name,
                ];
            }),
            'items' => $this->whenLoaded('items'),
        ];
    }
}
