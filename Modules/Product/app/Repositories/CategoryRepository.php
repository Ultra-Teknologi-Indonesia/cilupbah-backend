<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\Category;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Illuminate\Database\Eloquent\Collection;

class CategoryRepository
{
    public function getPaginated(int $perPage = 20, bool $enabledOnly = true): LengthAwarePaginator
    {
        $query = QueryBuilder::for(Category::class)
            ->allowedIncludes('parent', 'children', 'children.children', 'children.children.children', 'attributes')
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('parent_id'),
                AllowedFilter::exact('source')
            )
            ->allowedSorts('name', 'created_at');

        if ($enabledOnly) {
            $query->where('is_enabled', true);
        }

        return $query->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function getAll(bool $enabledOnly = true): Collection
    {
        $query = QueryBuilder::for(Category::class)
            ->allowedIncludes('parent', 'children', 'children.children', 'children.children.children', 'attributes')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('parent_id'),
                AllowedFilter::exact('source')
            )
            ->allowedSorts('name', 'created_at');

        if ($enabledOnly) {
            $query->where('is_enabled', true);
        }

        $query->allowedSearch('name');

        if (! request('search') && ! request()->has('filter.parent_id')) {
            $query->whereNull('parent_id');
        }

        return $query->get();
    }

    public function getSystemCategories(): Collection
    {
        return QueryBuilder::for(Category::class)
            ->where('source', 'system')
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('is_active'),
                AllowedFilter::exact('parent_id'),
                AllowedFilter::exact('is_enabled')
            )
            ->allowedSorts('name', 'created_at')
            ->allowedIncludes('children', 'children.children', 'children.children.children')
            ->get();
    }

    public function findById(int $id): ?Category
    {
        return QueryBuilder::for(Category::class)
            ->allowedIncludes('parent', 'children', 'children.children', 'children.children.children', 'attributes')
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
