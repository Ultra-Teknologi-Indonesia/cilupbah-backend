<?php

namespace Modules\Tax\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Tax\Models\Tax;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class TaxRepository
{
    /** Listing pajak — Spatie Query Builder. */
    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(Tax::class)
            ->allowedSearch('name')
            ->allowedFilters(AllowedFilter::exact('is_active'))
            ->allowedSorts('name', 'rate', 'created_at')
            ->defaultSort('name')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findById(int $id): ?Tax
    {
        return Tax::find($id);
    }

    public function create(array $data): Tax
    {
        return Tax::create($data);
    }

    public function update(Tax $tax, array $data): Tax
    {
        $tax->update($data);

        return $tax;
    }

    public function delete(Tax $tax): void
    {
        $tax->delete();
    }
}
