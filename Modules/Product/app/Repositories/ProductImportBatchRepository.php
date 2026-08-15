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

    public function paginateRows(ProductImportBatch $batch): LengthAwarePaginator
    {
        return $batch->rows()
            ->when(request('status'), function ($query, $status) {
                if ($status === 'valid') {
                    $query->where('status', 'valid');
                } elseif ($status === 'invalid' || $status === 'failed') {
                    $query->whereIn('status', ['invalid', 'failed']);
                }
            })
            ->when(request('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('sku', 'ilike', "%{$search}%")
                        ->orWhere('name', 'ilike', "%{$search}%")
                        ->orWhere('message', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('row_number')
            ->paginate((int) request('per_page', 20))
            ->appends(request()->query());
    }
}
