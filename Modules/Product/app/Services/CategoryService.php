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
                $channels["{$ch->code}_category_name"] = $mapped
                    ? $this->buildChannelCategoryFullName($mapped)
                    : null;
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

    public function getAttributeMappingList(int $categoryId): array
    {
        return $this->buildMappingList($categoryId, 'spec', false);
    }

    public function getVariationMappingList(int $categoryId): array
    {
        return $this->buildMappingList($categoryId, 'sales', true);
    }

    protected function buildMappingList(int $categoryId, string $type, bool $isSaleProp): array
    {
        $category = $this->getCategoryById($categoryId);

        $attributes = DB::table('category_attributes')
            ->join('attributes', 'attributes.id', '=', 'category_attributes.attribute_id')
            ->where('category_attributes.category_id', $categoryId)
            ->where('attributes.type', $type)
            ->select('attributes.id', 'attributes.name')
            ->orderBy('attributes.name')
            ->get();

        $channels = Channel::orderBy('name')->get(['id', 'code', 'name']);

        $channelCategoryMap = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $categoryId)
            ->select('channel_categories.id', 'channel_categories.channel_id')
            ->get()
            ->keyBy('channel_id');

        $result = [];
        foreach ($attributes as $attr) {
            $row = [
                'attribute_id' => $attr->id,
                'attribute_name' => $attr->name,
                'channels' => [],
            ];

            foreach ($channels as $channel) {
                $ccEntry = $channelCategoryMap->get($channel->id);
                $mapping = null;

                if ($ccEntry) {
                    $mapping = DB::table('attribute_channel_mappings as acm')
                        ->join('channel_attributes as ca', 'ca.id', '=', 'acm.channel_attribute_id')
                        ->where('acm.attribute_id', $attr->id)
                        ->where('ca.channel_category_id', $ccEntry->id)
                        ->where('ca.is_sale_prop', $isSaleProp)
                        ->select('ca.id as channel_attribute_id', 'ca.name as channel_attribute_name', 'ca.external_id')
                        ->first();
                }

                $row['channels'][] = [
                    'channel_id' => $channel->id,
                    'channel_code' => $channel->code,
                    'channel_name' => $channel->name,
                    'channel_category_id' => $ccEntry?->id,
                    'mapped_channel_attribute_id' => $mapping?->channel_attribute_id,
                    'mapped_channel_attribute_name' => $mapping?->channel_attribute_name ?? '-',
                ];
            }

            $result[] = $row;
        }

        return $result;
    }

    public function getAvailableChannelAttributes(int $categoryId, string $channelId, bool $isSaleProp): array
    {
        $channelCategoryId = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $categoryId)
            ->where('channel_categories.channel_id', $channelId)
            ->value('channel_categories.id');

        if (!$channelCategoryId) {
            return [];
        }

        return DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategoryId)
            ->where('is_sale_prop', $isSaleProp)
            ->select('id', 'name', 'external_id', 'is_required')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    private function buildChannelCategoryFullName(\Modules\Product\Models\ChannelCategory $cc): string
    {
        $parts = [$cc->name];
        $current = $cc;

        while ($current->parent_external_id && $current->parent_external_id !== '0') {
            $current = \Modules\Product\Models\ChannelCategory::where('channel_id', $current->channel_id)
                ->where('external_id', $current->parent_external_id)
                ->first();
            if (! $current) break;
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
