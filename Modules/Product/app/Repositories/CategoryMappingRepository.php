<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Category;
use Spatie\QueryBuilder\QueryBuilder;

class CategoryMappingRepository
{
    public function paginateEnabledLeafCategories(): LengthAwarePaginator
    {
        return QueryBuilder::for(Category::class)
            ->where('is_enabled', true)
            ->where('is_leaf', true)
            ->with(['channelCategories.channel'])
            ->allowedSearch('name')
            ->orderBy('name')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function attributesForCategory(int $categoryId, string $type): Collection
    {
        return DB::table('category_attributes')
            ->join('attributes', 'attributes.id', '=', 'category_attributes.attribute_id')
            ->where('category_attributes.category_id', $categoryId)
            ->where('attributes.type', $type)
            ->select('attributes.id', 'attributes.name')
            ->orderBy('attributes.name')
            ->get();
    }

    public function channelCategoryMapForCategory(int $categoryId): Collection
    {
        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $categoryId)
            ->select('channel_categories.id', 'channel_categories.channel_id')
            ->get()
            ->keyBy('channel_id');
    }

    public function findMappedChannelAttribute(int $attributeId, $channelCategoryId, bool $isSaleProp)
    {
        return DB::table('attribute_channel_mappings as acm')
            ->join('channel_attributes as ca', 'ca.id', '=', 'acm.channel_attribute_id')
            ->where('acm.attribute_id', $attributeId)
            ->where('ca.channel_category_id', $channelCategoryId)
            ->where('ca.is_sale_prop', $isSaleProp)
            ->select('ca.id as channel_attribute_id', 'ca.name as channel_attribute_name', 'ca.external_id')
            ->first();
    }

    public function resolveChannelCategoryId(int $categoryId, string $channelId)
    {
        return DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('category_channel_mappings.category_id', $categoryId)
            ->where('channel_categories.channel_id', $channelId)
            ->value('channel_categories.id');
    }

    public function availableChannelAttributes(string $channelCategoryId, bool $isSaleProp): array
    {
        return DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategoryId)
            ->where('is_sale_prop', $isSaleProp)
            ->select('id', 'name', 'external_id', 'is_required')
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    public function upsertAttributeMapping(int $attributeId, string $channelAttributeId): void
    {
        DB::table('attribute_channel_mappings')->updateOrInsert(
            [
                'attribute_id' => $attributeId,
                'channel_attribute_id' => $channelAttributeId,
            ],
            ['updated_at' => now(), 'created_at' => now()]
        );
    }

    public function removeAttributeMapping(int $attributeId, string $channelAttributeId): void
    {
        DB::table('attribute_channel_mappings')
            ->where('attribute_id', $attributeId)
            ->where('channel_attribute_id', $channelAttributeId)
            ->delete();
    }
}
