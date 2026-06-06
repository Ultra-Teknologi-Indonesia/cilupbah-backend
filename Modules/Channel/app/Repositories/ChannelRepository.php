<?php

namespace Modules\Channel\Repositories;

use Modules\Channel\Models\Channel;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;


class ChannelRepository
{
    public function getPaginatedChannels()
    {
        return QueryBuilder::for(Channel::class)
            ->with('shops')
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                'code'
            )
            ->allowedSorts('name', 'created_at', 'id')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
