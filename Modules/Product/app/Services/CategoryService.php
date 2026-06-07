<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Product\Models\Category;
use Modules\Product\Repositories\CategoryRepository;

class CategoryService
{
    protected CategoryRepository $repository;

    public function __construct(CategoryRepository $repository)
    {
        $this->repository = $repository;
    }

    public function getPaginatedCategories(int $perPage = 10): LengthAwarePaginator
    {
        return $this->repository->getPaginated($perPage);
    }

    public function getAllCategories(): Collection
    {
        return $this->repository->getAll();
    }

    public function getCategoryById(int $id): ?Category
    {
        $category = $this->repository->findById($id);
        if (!$category) {
            throw new Exception("Category not found");
        }
        return $category;
    }

    public function createCategory(array $data): Category
    {
        return $this->repository->create($data);
    }

    public function updateCategory(int $id, array $data): Category
    {
        $category = $this->getCategoryById($id);
        return $this->repository->update($category, $data);
    }

    public function deleteCategory(int $id): bool
    {
        $category = $this->getCategoryById($id);

        if ($category->children()->count() > 0) {
            throw new Exception("Cannot delete category because it has child categories");
        }

        if ($category->products()->count() > 0) {
            throw new Exception("Cannot delete category because it is used by products");
        }

        return $this->repository->delete($category);
    }

    public function mapToChannel(int $categoryId, array $channelCategoryIds): Category
    {
        $category = $this->getCategoryById($categoryId);
        $category->channelCategories()->sync($channelCategoryIds);
        
        return $category->load('channelCategories');
    }
}
