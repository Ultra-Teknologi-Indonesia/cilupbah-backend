<?php

namespace Modules\Sales\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sales\Models\SalesOrderImportBatch;
use Spatie\QueryBuilder\QueryBuilder;

class SalesOrderImportBatchRepository
{

    public function paginate(?string $state = null, int $perPage = 25): LengthAwarePaginator
    {
        return QueryBuilder::for(SalesOrderImportBatch::class)
            ->when($state, fn ($q) => $q->where('state', $state))
            ->latest()
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function find(string $id): ?SalesOrderImportBatch
    {
        return SalesOrderImportBatch::find($id);
    }

    public function paginateErrors(SalesOrderImportBatch $batch, int $perPage = 50): LengthAwarePaginator
    {
        return $batch->errors()
            ->orderBy('row_number')
            ->paginate($perPage)
            ->appends(request()->query());
    }
}
