<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductVariantRepository
{

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductVariant::class)
            ->with(['product:id,name,sku'])
            ->allowedFilters(
                AllowedFilter::partial('sku'),
                AllowedFilter::exact('product_id'),
                AllowedFilter::exact('is_active'),
            )
            ->allowedSorts('sku', 'sell_price', 'created_at')
            ->defaultSort('sku')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findById(string $id): ?ProductVariant
    {
        return ProductVariant::find($id);
    }

    public function delete(ProductVariant $variant): void
    {
        $variant->delete();
    }
}
