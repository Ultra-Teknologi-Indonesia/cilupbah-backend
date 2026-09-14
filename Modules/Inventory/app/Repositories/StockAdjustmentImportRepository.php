<?php

declare(strict_types=1);

namespace Modules\Inventory\Repositories;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Modules\Inventory\Models\Inventory;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;

final class StockAdjustmentImportRepository
{
    public function findVariantsBySkus(array $skus): EloquentCollection
    {
        return ProductVariant::query()
            ->whereIn('sku', $skus)
            ->whereHas('product', fn ($query) => $query->whereNull('deleted_at'))
            ->get(['id', 'sku', 'product_id'])
            ->keyBy(fn (ProductVariant $variant): string => strtolower($variant->sku));
    }

    public function findFinalBinsByCodes(string $locationId, array $codes): EloquentCollection
    {
        return LocationBin::query()
            ->where('location_id', $locationId)
            ->whereIn('bin_final_code', $codes)
            ->get(['id', 'bin_final_code', 'is_inbound'])
            ->keyBy('bin_final_code');
    }

    public function productNamesByIds(array $productIds): Collection
    {
        return Product::query()
            ->whereIn('id', $productIds)
            ->pluck('name', 'id');
    }

    public function findInventory(string $itemId, string $locationId, string $binId): ?Inventory
    {
        return Inventory::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('bin_id', $binId)
            ->first();
    }

    public function findPrimaryFinalInventory(string $itemId, string $locationId): ?Inventory
    {
        return Inventory::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->whereNotNull('bin_id')
            ->where('on_hand', '>', 0)
            ->with('bin:id,bin_final_code')
            ->whereHas('bin', fn ($query) => $query->where('is_inbound', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public function findAnyFinalInventory(string $itemId, string $locationId): ?Inventory
    {
        return Inventory::query()
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->whereNotNull('bin_id')
            ->with('bin:id,bin_final_code')
            ->whereHas('bin', fn ($query) => $query->where('is_inbound', false))
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }
}
