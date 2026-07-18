<?php

namespace Modules\Product\Http\Resources;

class ReviewItemResource extends MasterItemResource
{
    protected function variantList(): array
    {
        $variants = parent::variantList();

        if (! $this->resource->relationLoaded('variants')) {
            return $variants;
        }

        $qtyByVariant = $this->variants->mapWithKeys(function ($variant) {
            $inventories = $variant->relationLoaded('inventories') ? $variant->inventories : collect();

            $summary = \Modules\Inventory\Support\StockSummary::partitionLoaded($inventories);

            return [$variant->id => [
                'end_qty' => (int) $summary['on_hand'],
                'order_qty' => (int) $summary['on_order'],
                'available_qty' => max(0, (int) $summary['available']),
            ]];
        });

        return array_map(
            fn (array $variant) => $variant + ($qtyByVariant[$variant['item_id']] ?? []),
            $variants
        );
    }
}
