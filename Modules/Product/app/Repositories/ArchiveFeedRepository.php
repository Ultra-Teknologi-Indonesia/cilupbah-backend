<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ArchiveFeedRepository
{
    private const RELATIONS = [
        'category',
        'archivedBy:id,email',
        'variationTypes.attribute',
        'variants.options.attribute',
        'variants.channelMappings.channelMapping.channelShop.channel',
        'media',
        'channelMappings.channelShop.channel',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('status', Product::STATUS_ARCHIVED)
            ->with(self::RELATIONS)
            ->allowedSearch('name', 'sku')
            ->allowedFilters(
                AllowedFilter::exact('archived_by'),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('archived_from', fn ($query, $value) => $query->where('archived_at', '>=', $value)),
                AllowedFilter::callback('archived_to', fn ($query, $value) => $query->where('archived_at', '<=', $value)),
            )
            ->allowedSorts('name', 'created_at', 'archived_at')
            ->defaultSort('-archived_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function find(string $id): Product
    {
        return Product::query()
            ->where('status', Product::STATUS_ARCHIVED)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }
}
