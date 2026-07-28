<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;

class WooCommerceToInternalProductMapper
{
    public function map(array $wcProduct, string $shopId): array
    {
        $status = strtolower((string) ($wcProduct['status'] ?? ''));

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $this->primaryCategoryId($wcProduct)),
            'name' => $wcProduct['name'] ?? 'WooCommerce Product',
            'description' => (string) ($wcProduct['description'] ?? $wcProduct['short_description'] ?? ''),
            'condition' => 'NEW',
            'is_draft' => $status !== '' && $status !== 'publish',
            'is_active' => true,
            'status' => Product::STATUS_DOWNLOAD,
            'is_from_channel' => true,
            'verified_at' => now(),
            'weight' => (float) ($wcProduct['weight'] ?? 0),
        ];

        $internal['media'] = $this->mediaFromImages($wcProduct['images'] ?? []);

        $variations = $this->variationObjects($wcProduct['variations'] ?? []);
        $internal['variation_types'] = $this->mapVariationTypes($wcProduct['attributes'] ?? []);
        $internal['variants'] = $this->mapVariants($variations);

        if (empty($internal['variants'])) {
            $price = (float) ($wcProduct['regular_price'] ?? $wcProduct['price'] ?? 0);
            $internal['variants'][] = [
                'sku' => ! empty($wcProduct['sku']) ? $wcProduct['sku'] : null,
                'sell_price' => $price,
                'buy_price' => $price,
                'is_active' => true,
            ];
        }

        $internal['sku'] = ! empty($wcProduct['sku']) ? $wcProduct['sku'] : ($internal['variants'][0]['sku'] ?? null);

        $internal['channel_external_product_id'] = isset($wcProduct['id']) ? (string) $wcProduct['id'] : null;
        $internal['channel_shop_id_external'] = $shopId;

        return $internal;
    }

    protected function variationObjects(array $variations): array
    {
        return array_values(array_filter($variations, fn ($v) => is_array($v)));
    }

    protected function mediaFromImages(array $images): array
    {
        $media = [];
        foreach (array_values($images) as $idx => $image) {
            $url = is_array($image) ? ($image['src'] ?? null) : $image;
            if (! $url) {
                continue;
            }
            $media[] = [
                'media_type' => 'image',
                'url' => $url,
                'is_primary' => $idx === 0,
                'sort_order' => $idx,
            ];
        }

        return $media;
    }

    protected function mapVariationTypes(array $attributes): array
    {
        $types = [];
        foreach (array_values($attributes) as $i => $attr) {
            if (! is_array($attr)) {
                continue;
            }
            if (array_key_exists('variation', $attr) && ! $attr['variation']) {
                continue;
            }
            $name = $attr['name'] ?? null;
            if (! $name) {
                continue;
            }
            $types[] = ['name' => (string) $name, 'sort_order' => $i];
        }

        return $types;
    }

    protected function mapVariants(array $variations): array
    {
        $variants = [];

        foreach ($variations as $variation) {
            $sku = ! empty($variation['sku']) ? $variation['sku'] : null;
            $price = (float) ($variation['regular_price'] ?? $variation['price'] ?? 0);

            $variant = [
                'sku' => $sku,
                'sell_price' => $price,
                'buy_price' => $price,
                'weight' => (float) ($variation['weight'] ?? 0),
                'is_active' => strtolower((string) ($variation['status'] ?? 'publish')) === 'publish',
            ];

            $options = [];
            foreach ($variation['attributes'] ?? [] as $attr) {
                $name = $attr['name'] ?? null;
                $value = $attr['option'] ?? null;
                if (! $name || $value === null || $value === '') {
                    continue;
                }
                $options[] = ['name' => (string) $name, 'value' => (string) $value];
            }
            if ($options) {
                $variant['options'] = $options;
            }

            $imageUrl = $variation['image']['src'] ?? null;
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

    protected function primaryCategoryId(array $wcProduct)
    {
        foreach ($wcProduct['categories'] ?? [] as $category) {
            if (! empty($category['id'])) {
                return (string) $category['id'];
            }
        }

        return null;
    }

    protected function resolveCategoryId(string $shopId, $wcCategoryId)
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

        if (! $wcCategoryId) {
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
            ->where('channel_categories.external_id', (string) $wcCategoryId)
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
