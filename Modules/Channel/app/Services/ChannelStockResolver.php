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

        $componentsByBundle = [];
        $componentVariantIds = [];

        if (! empty($bundleProductIdByVariant)) {
            $rows = DB::table('product_bundle_items')
                ->whereIn('bundle_product_id', array_values(array_unique($bundleProductIdByVariant)))
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
            if (! isset($bundleProductIdByVariant[$variantId])) {
                $result[$variantId] = $availByItem[$variantId] ?? 0;
                continue;
            }

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
            ?: DB::table('locations')->where('location_code', Location::SYSTEM_KECIL_CODE)->value('id');

        return $id ? [$id] : [];
    }
}
