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

            $endQty = (int) $inventories->sum('on_hand');
            $orderQty = (int) $inventories->sum('on_order');

            return [$variant->id => [
                'end_qty' => $endQty,
                'order_qty' => $orderQty,
                'available_qty' => max(0, $endQty - $orderQty),
            ]];
        });

        return array_map(
            fn (array $variant) => $variant + ($qtyByVariant[$variant['item_id']] ?? []),
            $variants
        );
    }
}
