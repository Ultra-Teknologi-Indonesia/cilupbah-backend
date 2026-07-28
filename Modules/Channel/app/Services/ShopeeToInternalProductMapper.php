<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;

class ShopeeToInternalProductMapper
{
    public function map(array $shopeeItem, string $shopId): array
    {

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $shopeeItem['category_id'] ?? null),
            'name' => $shopeeItem['item_name'] ?? 'Shopee Product',
            'description' => $this->resolveDescription($shopeeItem),
            'condition' => strtoupper((string) ($shopeeItem['condition'] ?? 'NEW')) === 'USED' ? 'USED' : 'NEW',
            'is_draft' => strtoupper((string) ($shopeeItem['item_status'] ?? '')) !== 'NORMAL',
            'is_active' => true,
            'status' => Product::STATUS_DOWNLOAD,
            'is_from_channel' => true,
            'verified_at' => now(),
            'weight' => (float) ($shopeeItem['weight'] ?? 0),
            'length' => (float) ($shopeeItem['dimension']['package_length'] ?? 0),
            'width' => (float) ($shopeeItem['dimension']['package_width'] ?? 0),
            'height' => (float) ($shopeeItem['dimension']['package_height'] ?? 0),
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

        foreach ($shopeeItem['video_info'] ?? [] as $video) {
            $videoUrl = $video['video_url'] ?? null;
            if (! $videoUrl) {
                continue;
            }
            $internal['media'][] = [
                'media_type' => 'video',
                'url' => $videoUrl,
                'is_primary' => false,
                'sort_order' => count($internal['media']),
            ];
        }

        $tierVariation = $shopeeItem['tier_variation'] ?? [];
        $internal['variation_types'] = $this->mapVariationTypes($tierVariation);
        $internal['variants'] = $this->mapVariants($shopeeItem, $tierVariation);

        if (empty($internal['variants'])) {
            $internal['variants'][] = [
                'sku' => ! empty($shopeeItem['item_sku']) ? $shopeeItem['item_sku'] : null,
                'sell_price' => (float) ($shopeeItem['price_info'][0]['current_price'] ?? 0),
                'is_active' => true,
            ];
        }

        $internal['sku'] = $shopeeItem['item_sku'] ?? ($internal['variants'][0]['sku'] ?? null);

        $internal['channel_external_product_id'] = isset($shopeeItem['item_id']) ? (string) $shopeeItem['item_id'] : null;
        $internal['channel_shop_id_external'] = $shopId;

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
            $sku = ! empty($model['model_sku']) ? $model['model_sku'] : null;

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

        $mappings = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->join('categories', 'categories.id', '=', 'category_channel_mappings.category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $shopeeCategoryId)
            ->select('category_channel_mappings.category_id', 'categories.is_leaf')
            ->get();

        if ($mappings->isEmpty()) {
            return $fallback();
        }

        $leaves = $mappings->where('is_leaf', true);

        if ($leaves->count() === 1) {
            return (int) $leaves->first()->category_id;
        }

        if ($leaves->count() > 1) {
            return $fallback();
        }

        return (int) $mappings->first()->category_id;
    }
}
