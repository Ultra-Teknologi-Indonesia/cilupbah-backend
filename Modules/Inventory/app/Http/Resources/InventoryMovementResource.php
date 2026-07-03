<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Support\InventoryMovementSourceMap;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = InventoryMovementSourceMap::meta($this->source);
        $qty = (int) $this->qty;

        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
            'product_id' => $this->whenLoaded('product', fn () => $this->product?->product_id),
            'location_id' => $this->location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->location_name),
            'bin_id' => $this->bin_id,
            'bin_code' => $this->whenLoaded('bin', fn () => $this->bin?->bin_final_code),
            'transaction_number' => $this->transaction_number,
            'source' => $this->source,
            'source_category' => $meta['category'],
            'source_label' => $meta['label'],
            'is_variance' => InventoryMovementSourceMap::isVariance($this->source),
            'direction' => $qty > 0 ? 'in' : ($qty < 0 ? 'out' : 'none'),
            'qty' => $qty,
            'balance' => (int) ($this->total_balance ?? $this->balance),
            'transaction_date' => $this->transaction_date,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
        ];
    }
}
