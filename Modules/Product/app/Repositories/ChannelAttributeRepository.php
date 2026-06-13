<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ChannelAttribute;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class ChannelAttributeRepository
{
    public function getPaginated(string $channelCategoryId, int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(ChannelAttribute::class)
            ->where('channel_category_id', $channelCategoryId)
            ->allowedIncludes('options')
            ->allowedFilters(
                AllowedFilter::exact('is_required'),
                AllowedFilter::exact('is_multiple'),
                'name',
                'external_id'
            )
            ->defaultSort('name')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function getAllPaginated(): LengthAwarePaginator
    {
        return QueryBuilder::for(ChannelAttribute::class)
            ->with(['options', 'channelCategory'])
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('channel_category_id'),
                AllowedFilter::callback('channel_id', fn ($query, $value) => $query->whereHas('channelCategory', fn ($c) => $c->where('channel_id', $value))),
                AllowedFilter::exact('is_required'),
                AllowedFilter::exact('is_multiple'),
                'name',
                'external_id',
            )
            ->defaultSort('name')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function getAll(string $channelCategoryId): Collection
    {
        return QueryBuilder::for(ChannelAttribute::class)
            ->where('channel_category_id', $channelCategoryId)
            ->allowedIncludes('options')
            ->allowedFilters(
                AllowedFilter::exact('is_required'),
                AllowedFilter::exact('is_multiple'),
                'name',
                'external_id'
            )
            ->defaultSort('name')
            ->get();
    }

    public function findByExternalId(string $channelCategoryId, string $externalId): ?ChannelAttribute
    {
        return ChannelAttribute::where('channel_category_id', $channelCategoryId)
            ->where('external_id', $externalId)
            ->first();
    }

    public function upsert(array $data, array $uniqueBy): void
    {
        ChannelAttribute::upsert($data, $uniqueBy);
    }
}
