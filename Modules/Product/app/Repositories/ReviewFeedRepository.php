<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Support\ProductFeedRelations;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ReviewFeedRepository
{
    private const REVIEW_STATUSES = [
        Product::STATUS_DOWNLOAD,
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->whereIn('status', self::REVIEW_STATUSES)
            ->with(ProductFeedRelations::withInventories())
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::callback('status', function ($query, $value) {
                    if (in_array($value, self::REVIEW_STATUSES, true)) {
                        $query->where('status', $value);
                    }
                }),
            )
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
}
