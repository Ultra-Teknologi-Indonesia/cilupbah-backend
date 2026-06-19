<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\AttributeChannelMapping;
use Modules\Product\Models\AttributeOption;
use Modules\Product\Models\Category;
use Modules\Product\Models\CategoryAttribute;

class CategoryAttributeRepository
{
    public function exists(int $categoryId): bool
    {
        return Category::whereKey($categoryId)->exists();
    }

    public function isLeaf(int $categoryId): bool
    {
        return ! Category::where('parent_id', $categoryId)->exists();
    }

    public function formAttributes(int $categoryId): array
    {
        $catAttrs = CategoryAttribute::with('attribute')
            ->where('category_id', $categoryId)
            ->get()
            ->map(fn (CategoryAttribute $ca) => (object) [
                'id' => (int) $ca->attribute->id,
                'name' => $ca->attribute->name,
                'type' => $ca->attribute->type,
                'is_required' => $ca->is_required,
            ]);

        $attrIds = $catAttrs->pluck('id')->all() ?: [-1];

        $options = AttributeOption::whereIn('attribute_id', $attrIds)
            ->get(['id', 'attribute_id', 'value'])
            ->groupBy('attribute_id');

        $channelStatus = AttributeChannelMapping::with('channelAttribute.channelCategory.channel')
            ->whereIn('attribute_id', $attrIds)
            ->get()
            ->groupBy('attribute_id');

        $build = function ($a) use ($options, $channelStatus) {
            $channels = [];
            foreach ($channelStatus->get($a->id, collect()) as $mapping) {
                $code = $mapping->channelAttribute?->channelCategory?->channel?->code;
                if ($code === null) {
                    continue;
                }
                $channels[$code] = ['mapped' => true, 'required' => (bool) $mapping->channelAttribute->is_required];
            }

            return [
                'attribute_id' => (int) $a->id,
                'name' => $a->name,
                'is_required' => (bool) $a->is_required,
                'options' => $options->get($a->id, collect())
                    ->map(fn ($o) => ['id' => (int) $o->id, 'value' => $o->value])->values(),
                'channels' => (object) $channels,
            ];
        };

        return [
            'specifications' => $catAttrs->where('type', 'spec')->sortByDesc('is_required')->map($build)->values(),
            'variant_types' => $catAttrs->where('type', 'sales')->sortByDesc('is_required')->map($build)->values(),
        ];
    }
}
