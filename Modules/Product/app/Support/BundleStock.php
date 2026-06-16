<?php

namespace Modules\Product\Support;

use Modules\Product\Models\Product;

/**
 * B3 — Derivasi stok bundle (read-only). Bundle tidak punya ledger sendiri;
 * stoknya diturunkan dari komponen:
 *
 *   available_bundle = MIN atas komponen( floor(available_komponen / qty) )
 *
 * on_hand memakai rumus yang sama (jumlah bundle yang bisa dirakit dari stok fisik).
 * reserved & on_order = 0 (reservasi/PO hidup di komponen, bukan di bundle).
 *
 * Prasyarat eager-load: bundleItems.component.inventories.
 */
class BundleStock
{
    public static function derive(Product $product): ?array
    {
        if (! $product->is_bundle || ! $product->relationLoaded('bundleItems')) {
            return null;
        }

        $items = $product->bundleItems;

        if ($items->isEmpty()) {
            return ['on_hand' => 0, 'reserved' => 0, 'on_order' => 0, 'available' => 0];
        }

        $available = null;
        $onHand = null;

        foreach ($items as $item) {
            $qty = max(1, (int) $item->qty);
            $variant = $item->relationLoaded('component') ? $item->component : null;
            $inventories = ($variant && $variant->relationLoaded('inventories')) ? $variant->inventories : null;

            $compAvailable = $inventories ? (int) $inventories->sum('available') : 0;
            $compOnHand = $inventories ? (int) $inventories->sum('on_hand') : 0;

            $perAvailable = intdiv($compAvailable, $qty);
            $perOnHand = intdiv($compOnHand, $qty);

            $available = $available === null ? $perAvailable : min($available, $perAvailable);
            $onHand = $onHand === null ? $perOnHand : min($onHand, $perOnHand);
        }

        return [
            'on_hand' => $onHand ?? 0,
            'reserved' => 0,
            'on_order' => 0,
            'available' => $available ?? 0,
        ];
    }
}
