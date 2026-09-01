<?php

namespace Modules\Inventory\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Product\Support\TechnicalSku;

trait AppliesStockMonitorFilters
{
    protected function applyCommonFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $term = '%'.trim($filters['search']).'%';
            $query->where(function ($q) use ($term) {
                $q->where(function ($skuQuery) use ($term) {
                    TechnicalSku::exclude($skuQuery, 'product_variants.sku')
                        ->where('product_variants.sku', 'ilike', $term);
                })
                    ->orWhere('products.name', 'ilike', $term);
            });
        }

        if (! empty($filters['category_id'])) {
            $query->where('products.category_id', $filters['category_id']);
        }

        return $query;
    }
}
