<?php

namespace Modules\Product\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Product\Support\BundleStock;

class ProductStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {

        $derived = BundleStock::derive($this->resource);

        if ($derived !== null) {
            return array_merge(['item_id' => $this->id, 'sku' => $this->sku], $derived);
        }

        $inventories = $this->variants->flatMap->inventories;
        $summary = \Modules\Inventory\Support\StockSummary::partitionLoaded($inventories);

        return [
            'item_id' => $this->id,
            'sku' => $this->sku,
            'on_hand' => $summary['on_hand'],
            'pending_placement' => $summary['pending_placement'],
            'reserved' => $summary['reserved'],
            'on_order' => $summary['on_order'],
            'available' => (int) $inventories->sum('available'),
        ];
    }
}
