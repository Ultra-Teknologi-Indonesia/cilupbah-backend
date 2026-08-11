<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Modules\Product\Support\ProductFeedRelations;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ArchiveFeedRepository
{
    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('status', Product::STATUS_ARCHIVED)
            ->with(ProductFeedRelations::withArchivedBy())
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::exact('archived_by'),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('archived_from', fn ($query, $value) => $query->where('archived_at', '>=', $value)),
                AllowedFilter::callback('archived_to', fn ($query, $value) => $query->where('archived_at', '<=', $value)),
            )
            ->allowedSorts('name', 'created_at', 'archived_at')
            ->defaultSort('-archived_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): Product
    {
        return Product::query()
            ->where('status', Product::STATUS_ARCHIVED)
            ->with(ProductFeedRelations::withArchivedBy())
            ->findOrFail($id);
    }
}
