<?php

namespace Modules\Sales\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Warehouse\Models\Location;

class OrderDirectCompletionRepository
{
    public function ordersForCompletion(array $orderIds): Collection
    {
        return SalesOrder::with('items')
            ->whereIn('id', $orderIds)
            ->get();
    }

    public function lockOrder(string $orderId): ?SalesOrder
    {
        return SalesOrder::with('items')
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();
    }

    public function orderIdsWithPicklist(array $orderIds): array
    {
        return DB::table('picklist_items')
            ->whereIn('order_id', $orderIds)
            ->distinct()
            ->pluck('order_id')
            ->all();
    }

    public function picklistNumberForOrder(string $orderId): ?string
    {
        return DB::table('picklist_items')
            ->join('picklists', 'picklists.id', '=', 'picklist_items.picklist_id')
            ->where('picklist_items.order_id', $orderId)
            ->value('picklists.picklist_no');
    }

    public function sourceLocationId(): ?string
    {
        return DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');
    }

    public function bundleComponents(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $bundleProductByVariant = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('product_variants.id', $itemIds)
            ->where('products.is_bundle', true)
            ->pluck('product_variants.product_id', 'product_variants.id')
            ->all();

        if (empty($bundleProductByVariant)) {
            return [];
        }

        $rows = DB::table('product_bundle_items')
            ->whereIn('bundle_product_id', array_values(array_unique($bundleProductByVariant)))
            ->get(['bundle_product_id', 'component_variant_id', 'qty']);

        $componentsByProduct = [];
        foreach ($rows as $row) {
            $componentsByProduct[$row->bundle_product_id][] = [
                'item_id' => $row->component_variant_id,
                'qty' => max(1, (int) $row->qty),
            ];
        }

        $result = [];
        foreach ($bundleProductByVariant as $variantId => $productId) {
            $result[$variantId] = $componentsByProduct[$productId] ?? [];
        }

        return $result;
    }

    public function binStocks(array $itemIds, string $locationId): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $rows = DB::table('inventories as i')
            ->join('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->whereIn('i.item_id', $itemIds)
            ->where('i.location_id', $locationId)
            ->where('b.is_inbound', false)
            ->where('i.on_hand', '>', 0)
            ->orderByDesc('i.on_hand')
            ->get([
                'i.item_id',
                'i.bin_id',
                'i.on_hand',
                'b.bin_final_code',
            ]);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->item_id][] = [
                'bin_id' => $row->bin_id,
                'bin_code' => $row->bin_final_code,
                'on_hand' => (int) $row->on_hand,
            ];
        }

        return $result;
    }

    public function binsBelongingToLocation(array $binIds, string $locationId): array
    {
        if (empty($binIds)) {
            return [];
        }

        return DB::table('location_bins')
            ->whereIn('id', $binIds)
            ->where('location_id', $locationId)
            ->where('is_inbound', false)
            ->pluck('id')
            ->all();
    }

    public function variantMeta(array $itemIds): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $rows = DB::table('product_variants as v')
            ->leftJoin('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $itemIds)
            ->get(['v.id', 'v.sku', 'p.name']);

        $result = [];
        foreach ($rows as $row) {
            $result[$row->id] = [
                'sku' => (string) ($row->sku ?? ''),
                'name' => (string) ($row->name ?? ''),
            ];
        }

        return $result;
    }
}
