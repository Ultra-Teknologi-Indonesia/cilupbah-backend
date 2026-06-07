<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Models\ChannelCategory;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ChannelCategoryRepository
{
    public function getPaginated(string $channelId, int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(ChannelCategory::class)
            ->where('channel_id', $channelId)
            ->allowedIncludes('children', 'parent', 'children.children')
            ->allowedFilters(
                AllowedFilter::exact('is_leaf'),
                AllowedFilter::exact('parent_external_id'),
                'name',
                'external_id'
            )
            ->defaultSort('name')
            ->paginate($perPage);
    }

    public function getAll(string $channelId): Collection
    {
        return QueryBuilder::for(ChannelCategory::class)
            ->where('channel_id', $channelId)
            ->allowedIncludes('children', 'parent', 'children.children')
            ->allowedFilters(
                AllowedFilter::exact('is_leaf'),
                AllowedFilter::exact('parent_external_id'),
                'name',
                'external_id'
            )
            ->defaultSort('name')
            ->get();
    }

    public function findByExternalId(string $channelId, string $externalId): ?ChannelCategory
    {
        return ChannelCategory::where('channel_id', $channelId)
            ->where('external_id', $externalId)
            ->first();
    }
}
