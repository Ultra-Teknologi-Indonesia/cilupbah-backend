<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Output stok teragregasi per produk (Jubelio: all-stocks).
 * Membutuhkan relasi `variants.inventories` ter-load.
 */
class ProductStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
