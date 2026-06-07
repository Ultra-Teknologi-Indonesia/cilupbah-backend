<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Category;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository
{
    public function getPaginated(int $perPage = 10): LengthAwarePaginator
    {
        return QueryBuilder::for(Category::class)
            ->allowedIncludes(['parent', 'children', 'children.children', 'children.children.children'])
            ->allowedSearch('name')
            ->allowedFilters([
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('parent_id'),
            ])
            ->allowedSorts('name', 'created_at')
            ->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function getAll(): Collection
    {
        return QueryBuilder::for(Category::class)
            ->allowedIncludes(['parent', 'children', 'children.children', 'children.children.children'])
            ->allowedSearch('name')
            ->allowedFilters([
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('parent_id'),
            ])
            ->allowedSorts('name', 'created_at')
            ->get();
    }

    public function findById(int $id): ?Category
    {
        return QueryBuilder::for(Category::class)
            ->allowedIncludes(['parent', 'children', 'children.children', 'children.children.children'])
            ->find($id);
    }

    public function create(array $data): Category
    {
        return Category::create($data);
    }

    public function update(Category $category, array $data): Category
    {
        $category->update($data);
        return $category;
    }

    public function delete(Category $category): bool
    {
        return $category->delete();
    }
}
