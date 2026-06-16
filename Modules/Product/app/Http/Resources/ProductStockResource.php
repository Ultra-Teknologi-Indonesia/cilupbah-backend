<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\BundleStock;

class ProductStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Bundle tak punya ledger sendiri → stok diturunkan dari komponen (B3).
        $derived = BundleStock::derive($this->resource);

        if ($derived !== null) {
            return array_merge(['item_id' => $this->id, 'sku' => $this->sku], $derived);
        }

        $inventories = $this->variants->flatMap->inventories;

        return [
            'item_id' => $this->id,
            'sku' => $this->sku,
            'on_hand' => (int) $inventories->sum('on_hand'),
            'reserved' => (int) $inventories->sum('reserved'),
            'on_order' => (int) $inventories->sum('on_order'),
            'available' => (int) $inventories->sum('available'),
        ];
    }
}
