<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

class ShopeeToInternalProductMapper
{
    public function map(array $shopeeItem, string $shopId): array
    {

        $dimension = $shopeeItem['dimension'] ?? [];

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $shopeeItem['category_id'] ?? null),
            'name' => $shopeeItem['item_name'] ?? 'Shopee Product',
            'description' => $this->resolveDescription($shopeeItem),
            'condition' => strtoupper((string) ($shopeeItem['condition'] ?? 'NEW')) === 'USED' ? 'USED' : 'NEW',
            'is_draft' => strtoupper((string) ($shopeeItem['item_status'] ?? '')) !== 'NORMAL',
            'is_active' => true,
            'status' => 'download',
            'weight' => (float) ($shopeeItem['weight'] ?? 0),
            'length' => (float) ($dimension['package_length'] ?? 0),
            'width' => (float) ($dimension['package_width'] ?? 0),
            'height' => (float) ($dimension['package_height'] ?? 0),
        ];

        $internal['media'] = [];
        foreach (array_values($shopeeItem['image']['image_url_list'] ?? []) as $idx => $url) {
            if (! $url) {
                continue;
            }
            $internal['media'][] = [
                'media_type' => 'image',
                'url' => $url,
                'is_primary' => $idx === 0,
                'sort_order' => $idx,
            ];
        }

        $tierVariation = $shopeeItem['tier_variation'] ?? [];
        $internal['variation_types'] = $this->mapVariationTypes($tierVariation);
        $internal['variants'] = $this->mapVariants($shopeeItem, $tierVariation);

        if (empty($internal['variants'])) {
            $internal['variants'][] = [
                'sku' => 'SHP-' . ($shopeeItem['item_id'] ?? uniqid()),
                'sell_price' => (float) ($shopeeItem['price_info'][0]['current_price'] ?? 0),
                'is_active' => true,
            ];
        }

        $internal['sku'] = $shopeeItem['item_sku'] ?? ($internal['variants'][0]['sku'] ?? null);

        return $internal;
    }

    protected function mapVariationTypes(array $tierVariation): array
    {
        $types = [];
        foreach ($tierVariation as $i => $tier) {
            $name = $tier['name'] ?? null;
            if (! $name) {
                continue;
            }
            $types[] = ['name' => $name, 'sort_order' => $i];
        }

        return $types;
    }

    protected function mapVariants(array $shopeeItem, array $tierVariation): array
    {
        $variants = [];

        foreach ($shopeeItem['model_list'] ?? [] as $model) {
            $sku = ! empty($model['model_sku'])
                ? $model['model_sku']
                : ('SHP-' . ($model['model_id'] ?? uniqid()));

            $price = (float) ($model['price_info'][0]['current_price'] ?? $model['original_price'] ?? 0);

            $variant = [
                'sku' => $sku,
                'sell_price' => $price,
                'buy_price' => $price,
                'is_active' => true,
            ];

            $options = [];
            foreach ($model['tier_index'] ?? [] as $tierPos => $optIdx) {
                $tier = $tierVariation[$tierPos] ?? null;
                if (! $tier || empty($tier['name'])) {
                    continue;
                }
                $optName = $tier['option_list'][$optIdx]['option'] ?? null;
                if ($optName === null || $optName === '') {
                    continue;
                }
                $options[] = ['name' => $tier['name'], 'value' => $optName];
            }
            if ($options) {
                $variant['options'] = $options;
            }

            $imageUrl = $this->resolveModelImage($tierVariation, $model);
            if ($imageUrl) {
                $variant['media'] = [[
                    'media_type' => 'image',
                    'url' => $imageUrl,
                    'is_primary' => true,
                    'sort_order' => 0,
                ]];
            }

            $variants[] = $variant;
        }

        return $variants;
    }

    protected function resolveDescription(array $shopeeItem): string
    {
        $desc = (string) ($shopeeItem['description'] ?? '');
        if ($desc !== '') {
            return $desc;
        }

        $fields = $shopeeItem['description_info']['extended_description']['field_list'] ?? [];
        $parts = [];
        foreach ($fields as $field) {
            if (($field['field_type'] ?? '') === 'text' && ! empty($field['text'])) {
                $parts[] = $field['text'];
            }
        }

        return implode("\n\n", $parts);
    }

    protected function resolveModelImage(array $tierVariation, array $model): ?string
    {
        $tierIndex = $model['tier_index'][0] ?? null;
        if ($tierIndex === null) {
            return null;
        }

        $option = $tierVariation[0]['option_list'][$tierIndex] ?? null;
        if (! $option) {
            return null;
        }

        return $option['image']['image_url']
            ?? $option['image_url']
            ?? ($option['image']['image_url_list'][0] ?? null);
    }

    protected function resolveCategoryId(string $shopId, $shopeeCategoryId)
    {
        $fallback = function () {
            $id = DB::table('categories')->where('name', 'Belum Dikategorikan')->value('id');

            return $id ?: DB::table('categories')->insertGetId([
                'name' => 'Belum Dikategorikan',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        if (! $shopeeCategoryId) {
            return $fallback();
        }

        $channelId = DB::table('channel_shops')->where('shop_id', $shopId)->value('channel_id');
        if (! $channelId) {
            return $fallback();
        }

        $mapping = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->join('categories', 'categories.id', '=', 'category_channel_mappings.category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $shopeeCategoryId)
            ->orderBy('categories.is_leaf', 'desc')
            ->select('category_channel_mappings.category_id')
            ->first();

        return $mapping ? (int) $mapping->category_id : $fallback();
    }
}
