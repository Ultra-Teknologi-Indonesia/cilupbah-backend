<?php

namespace Modules\Product\Repositories;

use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Sales\Models\SalesOrderItem;

class BundleGuardRepository
{
    /**
     * SKU varian (dari daftar id) yang merupakan bagian dari produk bundle.
     *
     * @param  string[]  $variantIds
     * @return string[]
     */
    public function bundleComponentSkus(array $variantIds): array
    {
        return ProductVariant::whereIn('id', $variantIds)
            ->whereHas('product', fn ($p) => $p->where('is_bundle', true))
            ->pluck('sku')
            ->all();
    }

    /** @param  string[]  $variantIds */
    public function hasInventoryMovements(array $variantIds): bool
    {
        return InventoryMovement::whereIn('item_id', $variantIds)->exists();
    }

    /** @param  string[]  $variantIds */
    public function hasSalesOrderLines(array $variantIds): bool
    {
        return SalesOrderItem::whereIn('item_id', $variantIds)->exists();
    }

    /** @param  string[]  $statuses */
    public function hasActiveChannelListing(string $productId, array $statuses): bool
    {
        return ProductChannelMapping::where('product_id', $productId)
            ->whereIn('sync_status', $statuses)
            ->exists();
    }
}
