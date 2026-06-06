<?php

namespace Modules\Channel\Repositories;

use Modules\Channel\Models\Channel;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class ChannelRepository
{
    public function getPaginatedChannels()
    {
        return QueryBuilder::for(Channel::class)
            ->with('shops')
            ->allowedFilters(
                AllowedFilter::custom('search', new FuzzyFilter(), 'name'),
                'code'
            )
            ->allowedSorts('name', 'created_at', 'id')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
