<?php

namespace Modules\Product\Repositories;

use Illuminate\Support\Facades\DB;

class CategoryAttributeRepository
{
    public function exists(int $categoryId): bool
    {
        return DB::table('categories')->where('id', $categoryId)->exists();
    }

    public function isLeaf(int $categoryId): bool
    {
        return ! DB::table('categories')->where('parent_id', $categoryId)->exists();
    }

    public function formAttributes(int $categoryId): array
    {
        $catAttrs = DB::table('category_attributes as cga')
            ->join('attributes as a', 'a.id', '=', 'cga.attribute_id')
            ->where('cga.category_id', $categoryId)
            ->get(['a.id', 'a.name', 'a.type', 'cga.is_required']);

        $attrIds = $catAttrs->pluck('id')->all() ?: [-1];

        $options = DB::table('attribute_options')
            ->whereIn('attribute_id', $attrIds)
            ->get(['id', 'attribute_id', 'value'])
            ->groupBy('attribute_id');

        $channelStatus = DB::table('attribute_channel_mappings as m')
            ->join('channel_attributes as ca', 'ca.id', '=', 'm.channel_attribute_id')
            ->join('channel_categories as cc', 'cc.id', '=', 'ca.channel_category_id')
            ->join('channels as ch', 'ch.id', '=', 'cc.channel_id')
            ->whereIn('m.attribute_id', $attrIds)
            ->get(['m.attribute_id', 'ch.code', 'ca.is_required'])
            ->groupBy('attribute_id');

        $build = function ($a) use ($options, $channelStatus) {
            $channels = [];
            foreach ($channelStatus->get($a->id, collect()) as $row) {
                $channels[$row->code] = ['mapped' => true, 'required' => (bool) $row->is_required];
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
            'specifications' => $catAttrs->where('type', 'spec')->map($build)->values(),
            'variant_types' => $catAttrs->where('type', 'sales')->map($build)->values(),
        ];
    }
}
