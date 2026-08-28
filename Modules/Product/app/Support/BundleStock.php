<?php

namespace Modules\Product\Support;

use Illuminate\Support\Collection;
use Modules\Inventory\Support\StockSummary;
use Modules\Product\Models\Product;
use Modules\Warehouse\Models\Location;

class BundleStock
{
    public static function derive(Product $product): ?array
    {
        $byLocation = self::deriveByLocation($product);

        if ($byLocation === null) {
            return null;
        }

        return [
            'on_hand' => (int) $byLocation->sum('on_hand'),
            'on_order' => (int) $byLocation->sum('on_order'),
            'available' => (int) $byLocation->sum('available'),
        ];
    }

    public static function deriveByLocation(Product $product): ?Collection
    {
        if (! $product->is_bundle || ! $product->relationLoaded('bundleItems')) {
            return null;
        }

        $items = $product->bundleItems
            ->filter(fn ($item) => $item->relationLoaded('component') && $item->component !== null)
            ->values();

        if ($items->isEmpty()) {
            return collect();
        }

        $inventoriesByComponent = $items->mapWithKeys(function ($item) {
            $inventories = $item->component->relationLoaded('inventories')
                ? $item->component->inventories
                : collect();

            $inventories = $inventories->reject(fn ($inventory) => $inventory->relationLoaded('location')
                && $inventory->location?->location_code === Location::SYSTEM_TRANSIT_CODE);

            return [(string) $item->component_variant_id => $inventories->groupBy('location_id')];
        });

        $locationIds = $inventoriesByComponent
            ->flatMap(fn ($locations) => $locations->keys())
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($locationIds->isEmpty()) {
            return collect();
        }

        return $locationIds->map(function (string $locationId) use ($items, $inventoriesByComponent) {
            $onHand = null;
            $available = null;
            $locationName = null;

            foreach ($items as $item) {
                $qty = max(1, (int) $item->qty);
                $componentRows = $inventoriesByComponent
                    ->get((string) $item->component_variant_id, collect())
                    ->get($locationId, collect());
                $summary = StockSummary::partitionLoaded($componentRows);
                $componentOnHand = self::floorDivide((int) $summary['on_hand'], $qty);
                $componentAvailable = self::floorDivide((int) $summary['available'], $qty);
                $representative = $componentRows->first();

                if ($locationName === null && $representative?->relationLoaded('location')) {
                    $locationName = $representative->location?->location_name;
                }

                $onHand = $onHand === null ? $componentOnHand : min($onHand, $componentOnHand);
                $available = $available === null ? $componentAvailable : min($available, $componentAvailable);
            }

            $onHand ??= 0;
            $available ??= 0;

            return [
                'location_id' => $locationId,
                'location_name' => $locationName,
                'on_hand' => $onHand,
                'on_order' => $onHand - $available,
                'available' => $available,
            ];
        })->values();
    }

    private static function floorDivide(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0
            ? $quotient - 1
            : $quotient;
    }
}
