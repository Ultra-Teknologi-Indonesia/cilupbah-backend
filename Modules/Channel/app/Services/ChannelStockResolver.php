<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\ChannelShop;
use Modules\Inventory\Support\StockSummary;
use Modules\Warehouse\Models\Location;

class ChannelStockResolver
{

    public function availableByVariant(ChannelShop $shop, iterable $variants): array
    {
        $variantIds = collect($variants)->pluck('id')->filter()->values()->all();
        $result = array_fill_keys($variantIds, 0);

        if (empty($variantIds)) {
            return $result;
        }

        $locationIds = $this->sourceLocationIds($shop);
        if (empty($locationIds)) {
            return $result;
        }

        $bundleProductIdByVariant = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.id', $variantIds)
            ->where('products.is_bundle', true)
            ->pluck('product_variants.product_id', 'product_variants.id')
            ->all();

        $bundlesByComponentVariant = [];
        $bundleItemsForVariants = DB::table('product_bundle_items')
            ->join('products', 'products.id', '=', 'product_bundle_items.bundle_product_id')
            ->whereIn('product_bundle_items.component_variant_id', $variantIds)
            ->where('products.is_bundle', true)
            ->where('products.is_active', true)
            ->get(['product_bundle_items.bundle_product_id', 'product_bundle_items.component_variant_id']);

        foreach ($bundleItemsForVariants as $bItem) {
            $bundlesByComponentVariant[$bItem->component_variant_id][] = $bItem->bundle_product_id;
        }

        $allBundleProductIds = array_values(array_unique(array_merge(
            array_values($bundleProductIdByVariant),
            ...array_values($bundlesByComponentVariant)
        )));

        $componentsByBundle = [];
        $componentVariantIds = [];

        if (! empty($allBundleProductIds)) {
            $rows = DB::table('product_bundle_items')
                ->whereIn('bundle_product_id', $allBundleProductIds)
                ->get(['bundle_product_id', 'component_variant_id', 'qty']);

            foreach ($rows as $row) {
                $componentsByBundle[$row->bundle_product_id][] = [
                    'variant_id' => $row->component_variant_id,
                    'qty' => max(1, (int) $row->qty),
                ];
                $componentVariantIds[] = $row->component_variant_id;
            }
        }

        $lookupIds = array_values(array_unique(array_merge($variantIds, $componentVariantIds)));

        $availByItem = [];
        $stocks = DB::table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->whereIn('i.item_id', $lookupIds)
            ->whereIn('i.location_id', $locationIds)
            ->groupBy('i.item_id')
            ->selectRaw('i.item_id as item_id')
            ->selectRaw(StockSummary::placedOnHandSql('i', 'b') . ' as oh')
            ->selectRaw(StockSummary::onOrderSql('i') . ' as r')
            ->get();

        foreach ($stocks as $row) {
            $availByItem[$row->item_id] = max(0, (int) $row->oh - (int) $row->r);
        }

        foreach ($variantIds as $variantId) {
            if (isset($bundleProductIdByVariant[$variantId])) {
                $components = $componentsByBundle[$bundleProductIdByVariant[$variantId]] ?? [];

                if (empty($components)) {
                    $result[$variantId] = 0;
                    continue;
                }

                $bundleAvailable = null;
                foreach ($components as $component) {
                    $perBundle = intdiv($availByItem[$component['variant_id']] ?? 0, $component['qty']);
                    $bundleAvailable = $bundleAvailable === null ? $perBundle : min($bundleAvailable, $perBundle);
                }

                $result[$variantId] = max(0, (int) $bundleAvailable);
                continue;
            }

            $ownAvailable = $availByItem[$variantId] ?? 0;

            if (isset($bundlesByComponentVariant[$variantId])) {
                $minSupported = $ownAvailable;
                foreach ($bundlesByComponentVariant[$variantId] as $bundleId) {
                    $components = $componentsByBundle[$bundleId] ?? [];
                    if (empty($components)) {
                        continue;
                    }

                    $thisComp = collect($components)->firstWhere('variant_id', $variantId);
                    $thisQty = max(1, (int) ($thisComp['qty'] ?? 1));

                    $maxBundlesPossible = null;
                    foreach ($components as $component) {
                        $perBundle = intdiv($availByItem[$component['variant_id']] ?? 0, $component['qty']);
                        $maxBundlesPossible = $maxBundlesPossible === null ? $perBundle : min($maxBundlesPossible, $perBundle);
                    }

                    $supportedQty = ($maxBundlesPossible ?? 0) * $thisQty;
                    $minSupported = min($minSupported, $supportedQty);
                }
                $result[$variantId] = max(0, (int) $minSupported);
            } else {
                $result[$variantId] = max(0, (int) $ownAvailable);
            }
        }

        return $this->applyPushBuffer($shop, $result);
    }

    private function applyPushBuffer(ChannelShop $shop, array $available): array
    {
        $buffer = (int) ($shop->stock_push_buffer ?? 0);

        if ($buffer <= 0) {
            return $available;
        }

        foreach ($available as $variantId => $qty) {
            $available[$variantId] = max(0, (int) $qty - $buffer);
        }

        return $available;
    }

    public function sourceLocationIds(ChannelShop $shop): array
    {
        if ($shop->stock_source_mode === 'total') {
            return DB::table('locations')
                ->where('is_warehouse', true)
                ->where('is_active', true)
                ->where('location_code', '!=', Location::SYSTEM_TRANSIT_CODE)
                ->pluck('id')
                ->all();
        }

        $id = $shop->stock_source_location_id
            ?: DB::table('channel_warehouses')->where('store_id', $shop->shop_id)->value('location_id')
            ?: Location::getOfficialSmallWarehouseId();

        return $id ? [$id] : [];
    }
}
