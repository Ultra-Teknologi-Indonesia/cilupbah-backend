<?php

namespace Modules\Inventory\Support;

use Illuminate\Database\Eloquent\Builder;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\PutawaySource;
use Spatie\QueryBuilder\Sorts\Sort;

final class PutawayReferenceNumberSort implements Sort
{
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $putawaysTable = $query->getModel()->getTable();
        $sourcesTable = (new PutawaySource)->getTable();
        $inboundsTable = (new Inbound)->getTable();
        $direction = $descending ? 'DESC' : 'ASC';

        $query->orderByRaw(
            "(SELECT MIN({$inboundsTable}.reference_number)"
            ." FROM {$sourcesTable}"
            ." INNER JOIN {$inboundsTable}"
            ." ON {$inboundsTable}.id = {$sourcesTable}.inbound_id"
            ." WHERE {$sourcesTable}.putaway_id = {$putawaysTable}.id)"
            ." {$direction} NULLS LAST",
        );
    }
}
