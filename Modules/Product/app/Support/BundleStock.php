<?php

namespace Modules\Product\Support;

use Modules\Product\Models\Product;

class BundleStock
{
    public static function derive(Product $product): ?array
    {
        if (! $product->is_bundle || ! $product->relationLoaded('bundleItems')) {
            return null;
        }

        $items = $product->bundleItems;

        if ($items->isEmpty()) {
            return ['on_hand' => 0, 'on_order' => 0, 'available' => 0];
        }

        $available = null;
        $onHand = null;

        foreach ($items as $item) {
            $qty = max(1, (int) $item->qty);
            $variant = $item->relationLoaded('component') ? $item->component : null;
            $inventories = ($variant && $variant->relationLoaded('inventories')) ? $variant->inventories : null;

            $summary = $inventories
                ? \Modules\Inventory\Support\StockSummary::partitionLoaded($inventories)
                : ['on_hand' => 0];
            $compAvailable = $inventories ? (int) $inventories->sum('available') : 0;
            $compOnHand = (int) $summary['on_hand'];

            $perAvailable = intdiv($compAvailable, $qty);
            $perOnHand = intdiv($compOnHand, $qty);

            $available = $available === null ? $perAvailable : min($available, $perAvailable);
            $onHand = $onHand === null ? $perOnHand : min($onHand, $perOnHand);
        }

        return [
            'on_hand' => $onHand ?? 0,
            'on_order' => 0,
            'available' => $available ?? 0,
        ];
    }
}
