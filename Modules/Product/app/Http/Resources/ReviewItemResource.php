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

            return [$variant->id => [
                'end_qty' => (int) $inventories->sum('on_hand'),
                'order_qty' => (int) $inventories->sum('on_order'),
                'available_qty' => (int) $inventories->sum('available'),
            ]];
        });

        return array_map(
            fn (array $variant) => $variant + ($qtyByVariant[$variant['item_id']] ?? []),
            $variants
        );
    }
}
