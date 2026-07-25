<?php

namespace Modules\Inventory\Http\Resources;

use Illuminate\Http\Request;
use App\Support\ActorName;
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
            'order_no' => $this->pick_order_no,
            'order_count' => $this->pick_order_count !== null ? (int) $this->pick_order_count : null,
            'reference_number' => $this->ref_no,
            'note' => $this->ref_note,
            'source' => $this->source,
            'source_category' => $meta['category'],
            'source_label' => $meta['label'],
            'is_variance' => InventoryMovementSourceMap::isVariance($this->source),
            'direction' => $qty > 0 ? 'in' : ($qty < 0 ? 'out' : 'none'),
            'qty' => $qty,
            'balance' => (int) ($this->total_balance ?? $this->balance),
            'transaction_date' => $this->transaction_date,
            'created_by' => ActorName::resolve($this->created_by),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
