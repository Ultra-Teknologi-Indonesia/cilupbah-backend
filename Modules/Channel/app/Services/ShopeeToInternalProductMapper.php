<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

/**
 * Item Shopee (get_item_base_info) → array internal Product untuk download/listing.
 */
class ShopeeToInternalProductMapper
{
    public function map(array $shopeeItem, string $shopId): array
    {
        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $shopeeItem['category_id'] ?? null),
            'name' => $shopeeItem['item_name'] ?? 'Shopee Product',
            'description' => $shopeeItem['description'] ?? '',
            'condition' => strtoupper((string) ($shopeeItem['condition'] ?? 'NEW')) === 'USED' ? 'USED' : 'NEW',
            'is_draft' => strtoupper((string) ($shopeeItem['item_status'] ?? '')) !== 'NORMAL',
            'is_active' => true,
            'status' => 'download',
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

        $internal['variants'] = $this->mapVariants($shopeeItem);

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

    protected function mapVariants(array $shopeeItem): array
    {
        $variants = [];
        $tierVariation = $shopeeItem['tier_variation'] ?? [];

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

    /** Gambar varian Shopee ada di opsi tier_variation, dipetakan via tier_index model. */
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

        $categoryId = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $shopeeCategoryId)
            ->value('category_channel_mappings.category_id');

        return $categoryId ?: $fallback();
    }
}
