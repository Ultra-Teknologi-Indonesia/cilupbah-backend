<?php

namespace Modules\Product\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
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
        $data['source'] = 'custom';
        $data['is_enabled'] = true;
        $data['is_leaf'] = true;

        if (!empty($data['parent_id'])) {
            $parentDepth = $this->computeDepth($data['parent_id']);
            if ($parentDepth >= 2) {
                throw new Exception('Maksimal 3 level kategori (kedalaman 0-2)');
            }
        }

        return DB::transaction(function () use ($data) {
            $category = $this->repository->create($data);

            if ($category->parent_id) {
                Category::where('id', $category->parent_id)
                    ->where('is_leaf', true)
                    ->update(['is_leaf' => false]);

                $this->enableParentsRecursive($category->parent_id);
            }

            return $category;
        });
    }

    private function computeDepth(int $categoryId): int
    {
        $depth = 0;
        $current = Category::find($categoryId);

        while ($current && $current->parent_id) {
            $depth++;
            $current = Category::find($current->parent_id);
        }

        return $depth;
    }

    public function updateCategory(int $id, array $data): Category
    {
        $category = $this->getCategoryById($id);
        return $this->repository->update($category, $data);
    }

    public function deleteCategory(int $id): bool
    {
        $category = $this->getCategoryById($id);

        if ($category->source === 'system') {
            throw new Exception("Kategori sistem tidak bisa dihapus, gunakan fitur nonaktifkan");
        }

        if ($category->children()->count() > 0) {
            throw new Exception("Tidak bisa menghapus kategori karena memiliki sub-kategori");
        }

        if ($category->products()->count() > 0) {
            throw new Exception("Tidak bisa menghapus kategori karena digunakan oleh produk");
        }

        return $this->repository->delete($category);
    }

    public function mapToChannel(int $categoryId, array $channelCategoryIds): Category
    {
        $category = $this->getCategoryById($categoryId);

        $incoming = \Modules\Product\Models\ChannelCategory::whereIn('id', $channelCategoryIds)->get();
        $channelIdsToReplace = $incoming->pluck('channel_id')->unique();

        DB::transaction(function () use ($category, $channelCategoryIds, $channelIdsToReplace) {
            $existingToRemove = $category->channelCategories()
                ->whereIn('channel_categories.channel_id', $channelIdsToReplace)
                ->pluck('channel_categories.id');

            $category->channelCategories()->detach($existingToRemove);
            $category->channelCategories()->attach($channelCategoryIds);
        });

        return $category->load('channelCategories');
    }

    public function getChannelMapping(int $categoryId): Category
    {
        return $this->getCategoryById($categoryId)->load('channelCategories.channel');
    }

    // --- System category management ---

    public function getSystemCategories(): Collection
    {
        return $this->repository->getSystemCategories();
    }

    public function enableSystemCategories(array $ids): int
    {
        $categories = Category::system()
            ->whereIn('id', $ids)
            ->where('is_leaf', true)
            ->get();

        if ($categories->isEmpty()) {
            throw new Exception("Tidak ada kategori sistem leaf yang ditemukan");
        }

        $enabled = 0;

        DB::transaction(function () use ($categories, &$enabled) {
            foreach ($categories as $category) {
                if (!$category->is_enabled) {
                    $category->update(['is_enabled' => true]);
                    $enabled++;
                }
                $this->enableParentsRecursive($category->parent_id);
            }
        });

        return $enabled;
    }

    public function disableSystemCategories(array $ids): int
    {
        $categories = Category::system()
            ->whereIn('id', $ids)
            ->where('is_leaf', true)
            ->get();

        if ($categories->isEmpty()) {
            throw new Exception("Tidak ada kategori sistem leaf yang ditemukan");
        }

        foreach ($categories as $category) {
            if ($category->products()->count() > 0) {
                throw new Exception("Kategori \"{$category->name}\" masih digunakan oleh produk");
            }
        }

        $disabled = 0;

        DB::transaction(function () use ($categories, &$disabled) {
            foreach ($categories as $category) {
                if ($category->is_enabled) {
                    $category->update(['is_enabled' => false]);
                    $disabled++;
                }
                $this->disableOrphanedParents($category->parent_id);
            }
        });

        return $disabled;
    }

    public function getMappingList(): LengthAwarePaginator
    {
        $allChannels = Channel::orderBy('name')->get(['id', 'code', 'name']);

        $query = Category::query()
            ->where('is_enabled', true)
            ->where('is_leaf', true)
            ->with(['channelCategories.channel']);

        if ($search = request('search')) {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $paginated = $query->orderBy('name')
            ->paginate(request('per_page', 15))
            ->appends(request()->query());

        $paginated->getCollection()->transform(function (Category $category) use ($allChannels) {
            $fullName = $this->buildFullCategoryName($category);

            $mappedByCode = [];
            foreach ($category->channelCategories as $cc) {
                $code = $cc->channel?->code;
                if ($code) {
                    $mappedByCode[$code] = $cc;
                }
            }

            $channels = [];
            foreach ($allChannels as $ch) {
                $mapped = $mappedByCode[$ch->code] ?? null;
                $channels["{$ch->code}_category_id"] = $mapped?->external_id;
                $channels["{$ch->code}_category_name"] = $mapped ? $mapped->name : null;
            }

            return array_merge([
                'category_id' => $category->id,
                'full_category_name' => $fullName,
                'source' => $category->source,
                'channels' => $allChannels->map(fn ($ch) => [
                    'id' => $ch->id,
                    'code' => $ch->code,
                    'name' => $ch->name,
                ])->values(),
            ], $channels);
        });

        return $paginated;
    }

    public function buildFullCategoryName(Category $category): string
    {
        $parts = [$category->name];
        $current = $category;

        while ($current->parent_id) {
            $current = Category::find($current->parent_id);
            if (!$current) break;
            array_unshift($parts, $current->name);
        }

        return implode(' > ', $parts);
    }

    private function enableParentsRecursive(?int $parentId): void
    {
        if (!$parentId) return;

        $parent = Category::find($parentId);
        if (!$parent || $parent->is_enabled) return;

        $parent->update(['is_enabled' => true]);
        $this->enableParentsRecursive($parent->parent_id);
    }

    private function disableOrphanedParents(?int $parentId): void
    {
        if (!$parentId) return;

        $parent = Category::find($parentId);
        if (!$parent) return;

        $hasEnabledChildren = $parent->children()
            ->where('is_enabled', true)
            ->exists();

        if (!$hasEnabledChildren) {
            $parent->update(['is_enabled' => false]);
            $this->disableOrphanedParents($parent->parent_id);
        }
    }
}
