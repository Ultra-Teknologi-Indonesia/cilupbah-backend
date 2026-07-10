<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductImportBatch;
use Spatie\QueryBuilder\QueryBuilder;

class ProductImportBatchRepository
{
    public function paginateBatches(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductImportBatch::class)
            ->when(request('type'), fn ($query, $type) => $query->where('type', $type))
            ->when(request('state'), fn ($query, $state) => $query->where('state', $state))
            ->orderByDesc('created_at')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): ?ProductImportBatch
    {
        return ProductImportBatch::find($id);
    }

    public function paginateErrors(ProductImportBatch $batch): LengthAwarePaginator
    {
        return $batch->errors()
            ->orderBy('row_number')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }
}
