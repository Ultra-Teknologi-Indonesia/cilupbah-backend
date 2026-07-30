<?php

namespace Modules\Product\Support;

use Modules\Product\Models\Product;

class BundleStock
{
    private static ?string $transitLocationId = null;

    private static bool $transitResolved = false;

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

            // Samakan scope dengan channel: kecualikan SYS-TRANSIT agar ERP tidak
            // overstate stok bundle (partitionLoaded sudah placed-only).
            if ($inventories !== null) {
                $transitLocationId = self::transitLocationId();
                if ($transitLocationId !== null) {
                    $inventories = $inventories->reject(
                        fn ($inv) => (string) $inv->location_id === (string) $transitLocationId
                    );
                }
            }

            $summary = $inventories
                ? \Modules\Inventory\Support\StockSummary::partitionLoaded($inventories)
                : ['on_hand' => 0, 'available' => 0];
            $compAvailable = max(0, (int) $summary['available']);
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

    private static function transitLocationId(): ?string
    {
        if (! self::$transitResolved) {
            self::$transitLocationId = \Modules\Warehouse\Models\Location::query()
                ->where('location_code', \Modules\Warehouse\Models\Location::SYSTEM_TRANSIT_CODE)
                ->value('id');
            self::$transitResolved = true;
        }

        return self::$transitLocationId;
    }
}
