<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Product;
use Spatie\QueryBuilder\QueryBuilder;

class MasterFeedRepository
{
    private const RELATIONS = [
        'category',
        'variationTypes.attribute',
        'variants.options.attribute',
        'variants.channelMappings.channelMapping.channelShop.channel',
        'media',
        'channelMappings.channelShop.channel',
    ];

    public function paginate(string $status, ?string $updatedSince = null): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->where('status', $status)
            ->when($updatedSince, fn ($query) => $query->where('updated_at', '>=', $updatedSince))
            ->with(self::RELATIONS)
            ->allowedSearch('name', 'sku')
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->defaultSort('-updated_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function find(string $id, string $status): Product
    {
        return Product::query()
            ->where('status', $status)
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }
}
