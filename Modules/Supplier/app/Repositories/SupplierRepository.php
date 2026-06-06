<?php

namespace Modules\Supplier\Repositories;

use Modules\Supplier\Models\Supplier;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class SupplierRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Supplier::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::custom('search', new FuzzyFilter('name,company_name,code,email'))
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function findById(string $id): ?Supplier
    {
        return Supplier::find($id);
    }

    public function create(array $data): Supplier
    {
        return Supplier::create($data);
    }

    public function update(Supplier $supplier, array $data): Supplier
    {
        $supplier->update($data);
        return $supplier->fresh();
    }

    public function delete(Supplier $supplier): bool
    {
        return $supplier->delete();
    }

    public function getActiveSuppliers()
    {
        return Supplier::where('status', Supplier::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }
}
