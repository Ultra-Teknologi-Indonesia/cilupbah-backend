<?php

namespace Modules\Inventory\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filter standar Monitor Stok (ADR-202).
 *
 * Dipakai oleh query berbasis ProductVariant (di-join ke `products`).
 * Catatan: filter `location_id` TIDAK di sini karena memengaruhi agregasi
 * SUM stok — ditangani di subquery `inventories` pada repository.
 */
trait AppliesStockMonitorFilters
{
    /**
     * @param  array{search?:string,category_id?:string}  $filters
     */
    protected function applyCommonFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $term = '%' . trim($filters['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('product_variants.sku', 'like', $term)
                    ->orWhere('products.name', 'like', $term);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('products.category_id', $filters['category_id']);
        }

        return $query;
    }
}
