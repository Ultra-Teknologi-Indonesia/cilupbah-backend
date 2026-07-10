<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesSettlement;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesSettlementRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(SalesSettlement::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('channel'),
            )
            ->allowedSorts('settlement_number', 'period_start', 'period_end', 'total_settlement', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?SalesSettlement
    {
        return SalesSettlement::find($id);
    }
}
