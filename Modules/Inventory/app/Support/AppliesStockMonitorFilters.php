<?php

namespace Modules\Inventory\Support;

use Illuminate\Database\Eloquent\Builder;

trait AppliesStockMonitorFilters
{

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
