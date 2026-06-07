<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Attribute;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class AttributeRepository
{
    public function getPaginated(int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(Attribute::class)
            ->allowedSearch('name')
            ->allowedFilters([
                AllowedFilter::exact('type'),
            ])
            ->allowedSorts('name', 'created_at', 'type')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function getAll(): Collection
    {
        return QueryBuilder::for(Attribute::class)
            ->allowedSearch('name')
            ->allowedFilters([
                AllowedFilter::exact('type'),
            ])
            ->allowedSorts('name', 'created_at', 'type')
            ->get();
    }

    public function findById(int $id): ?Attribute
    {
        return Attribute::find($id);
    }

    public function create(array $data): Attribute
    {
        return Attribute::create($data);
    }

    public function update(Attribute $attribute, array $data): Attribute
    {
        $attribute->update($data);
        return $attribute;
    }

    public function delete(Attribute $attribute): bool
    {
        return $attribute->delete();
    }
}
