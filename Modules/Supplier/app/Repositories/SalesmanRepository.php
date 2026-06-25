<?php

namespace Modules\Supplier\Repositories;

use Modules\Supplier\Models\Salesman;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;

class SalesmanRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(Salesman::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::custom('search', new FuzzyFilter('name,code,email,phone'))
            )
            ->allowedSorts('name', 'code', 'created_at')
            ->defaultSort('name')
            ->paginate($limit);
    }

    public function findById(string $id): ?Salesman
    {
        return Salesman::find($id);
    }

    public function create(array $data): Salesman
    {
        return Salesman::create($data);
    }

    public function update(Salesman $salesman, array $data): Salesman
    {
        $salesman->update($data);
        return $salesman->fresh();
    }

    public function delete(Salesman $salesman): bool
    {
        return $salesman->delete();
    }

    public function getAll()
    {
        return Salesman::where('status', 'active')->orderBy('name')->get();
    }
}
