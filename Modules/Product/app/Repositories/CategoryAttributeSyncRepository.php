<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\Collection;
use Modules\Product\Models\Attribute;
use Modules\Product\Models\AttributeChannelMapping;
use Modules\Product\Models\AttributeOption;
use Modules\Product\Models\CategoryAttribute;
use Modules\Product\Models\CategoryChannelMapping;
use Modules\Product\Models\ChannelAttribute;
use Modules\Product\Models\ChannelAttributeOption;

class CategoryAttributeSyncRepository
{
    public function channelCategoryIds(int $categoryId): Collection
    {
        return CategoryChannelMapping::where('category_id', $categoryId)->pluck('channel_category_id');
    }

    public function allMappedCategoryIds(): Collection
    {
        return CategoryChannelMapping::query()->distinct()->pluck('category_id');
    }

    public function channelAttributes(Collection $channelCategoryIds): Collection
    {
        return ChannelAttribute::whereIn('channel_category_id', $channelCategoryIds)->get();
    }

    public function mappedInternalAttributeId(string $channelAttributeId): ?int
    {
        $id = AttributeChannelMapping::where('channel_attribute_id', $channelAttributeId)->value('attribute_id');

        return $id !== null ? (int) $id : null;
    }

    public function findOrCreateAttribute(string $name, string $type): int
    {
        return (int) Attribute::firstOrCreate(['name' => $name], ['type' => $type])->id;
    }

    public function linkAttributeToChannel(int $attributeId, string $channelAttributeId): void
    {
        AttributeChannelMapping::firstOrCreate([
            'attribute_id' => $attributeId,
            'channel_attribute_id' => $channelAttributeId,
        ]);
    }

    public function findCategoryAttribute(int $categoryId, int $attributeId): ?CategoryAttribute
    {
        return CategoryAttribute::where('category_id', $categoryId)
            ->where('attribute_id', $attributeId)
            ->first();
    }

    public function createCategoryAttribute(int $categoryId, int $attributeId, bool $isRequired): void
    {
        CategoryAttribute::create([
            'category_id' => $categoryId,
            'attribute_id' => $attributeId,
            'is_required' => $isRequired,
        ]);
    }

    public function markCategoryAttributeRequired(CategoryAttribute $categoryAttribute): void
    {
        $categoryAttribute->update(['is_required' => true]);
    }

    public function channelAttributeOptions(string $channelAttributeId): Collection
    {
        return ChannelAttributeOption::where('channel_attribute_id', $channelAttributeId)
            ->get(['name', 'external_id']);
    }

    public function ensureAttributeOption(int $attributeId, string $value): void
    {
        AttributeOption::firstOrCreate([
            'attribute_id' => $attributeId,
            'value' => $value,
        ]);
    }
}
