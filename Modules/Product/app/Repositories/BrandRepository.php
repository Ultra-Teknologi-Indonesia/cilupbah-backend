<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Brand;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class BrandRepository
{
    public function getPaginated(int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(Brand::class)
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function getAll(): Collection
    {
        return QueryBuilder::for(Brand::class)
            ->allowedSearch('name')
            ->allowedSorts('name', 'created_at')
            ->get();
    }

    public function findById(int $id): ?Brand
    {
        return Brand::find($id);
    }

    public function create(array $data): Brand
    {
        return Brand::create($data);
    }

    public function update(Brand $brand, array $data): Brand
    {
        $brand->update($data);
        return $brand;
    }

    public function delete(Brand $brand): bool
    {
        return $brand->delete();
    }
}
