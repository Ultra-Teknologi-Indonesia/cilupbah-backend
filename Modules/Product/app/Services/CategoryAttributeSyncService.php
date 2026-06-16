<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Services\ChannelListingValidator;

class CategoryAttributeSyncService
{

    public function materializeFromChannels(int $categoryId): int
    {
        $channelCatIds = DB::table('category_channel_mappings')
            ->where('category_id', $categoryId)
            ->pluck('channel_category_id');

        if ($channelCatIds->isEmpty()) {
            return 0;
        }

        $system = array_map('mb_strtolower', ChannelListingValidator::SYSTEM_ATTRIBUTES);

        $channelAttrs = DB::table('channel_attributes')
            ->whereIn('channel_category_id', $channelCatIds)
            ->get();

        $added = 0;

        foreach ($channelAttrs as $ca) {
            if (in_array(mb_strtolower((string) $ca->external_id), $system, true)) {
                continue;
            }

            $attributeId = $this->resolveInternalAttribute($ca);

            $added += $this->upsertCategoryAttribute($categoryId, $attributeId, (bool) $ca->is_required);
            $this->materializeOptions($attributeId, $ca->id);
        }

        return $added;
    }

    public function materializeAllMapped(): array
    {
        $categoryIds = DB::table('category_channel_mappings')->distinct()->pluck('category_id');

        $result = [];
        foreach ($categoryIds as $cid) {
            $result[(int) $cid] = $this->materializeFromChannels((int) $cid);
        }

        return $result;
    }

    private function resolveInternalAttribute(object $ca): int
    {
        $mappedId = DB::table('attribute_channel_mappings')
            ->where('channel_attribute_id', $ca->id)
            ->value('attribute_id');
        if ($mappedId) {
            return (int) $mappedId;
        }

        $name = (string) ($ca->name ?: $ca->external_id);
        $type = $ca->is_sale_prop ? 'sales' : 'spec';

        $existing = DB::table('attributes')->where('name', $name)->first();
        $attributeId = $existing
            ? (int) $existing->id
            : (int) DB::table('attributes')->insertGetId([
                'name' => $name,
                'type' => $type,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('attribute_channel_mappings')->insertOrIgnore([
            'attribute_id' => $attributeId,
            'channel_attribute_id' => $ca->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $attributeId;
    }

    private function upsertCategoryAttribute(int $categoryId, int $attributeId, bool $isRequired): int
    {
        $existing = DB::table('category_attributes')
            ->where('category_id', $categoryId)
            ->where('attribute_id', $attributeId)
            ->first();

        if ($existing) {
            if ($isRequired && ! $existing->is_required) {
                DB::table('category_attributes')->where('id', $existing->id)
                    ->update(['is_required' => true, 'updated_at' => now()]);
            }

            return 0;
        }

        DB::table('category_attributes')->insert([
            'category_id' => $categoryId,
            'attribute_id' => $attributeId,
            'is_required' => $isRequired,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 1;
    }

    private function materializeOptions(int $attributeId, string $channelAttributeId): void
    {
        $opts = DB::table('channel_attribute_options')
            ->where('channel_attribute_id', $channelAttributeId)
            ->get(['name', 'external_id']);

        foreach ($opts as $opt) {
            $value = (string) ($opt->name ?: $opt->external_id);
            if ($value === '') {
                continue;
            }

            $exists = DB::table('attribute_options')
                ->where('attribute_id', $attributeId)
                ->where('value', $value)
                ->exists();

            if (! $exists) {
                DB::table('attribute_options')->insert([
                    'attribute_id' => $attributeId,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
