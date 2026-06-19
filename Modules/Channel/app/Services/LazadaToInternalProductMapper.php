<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

class LazadaToInternalProductMapper
{
    public function map(array $lazadaProduct, string $shopId): array
    {
        $attributes = $lazadaProduct['attributes'] ?? [];

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $lazadaProduct['primary_category'] ?? null),
            'name' => $attributes['name'] ?? 'Lazada Product',
            'description' => $attributes['description'] ?? '',
            'condition' => 'NEW',
            'is_draft' => strtolower((string) ($lazadaProduct['status'] ?? '')) !== 'active',
            'is_active' => true,
            'status' => 'download',
        ];

        $internal['media'] = [];
        foreach (array_values($lazadaProduct['images'] ?? []) as $idx => $url) {
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

        $internal['variants'] = [];
        foreach ($lazadaProduct['skus'] ?? [] as $skuData) {
            $sku = ! empty($skuData['SellerSku'])
                ? $skuData['SellerSku']
                : ('LZ-' . ($skuData['SkuId'] ?? uniqid()));

            $price = (float) ($skuData['special_price'] ?? 0) ?: (float) ($skuData['price'] ?? 0);

            $variant = [
                'sku' => $sku,
                'sell_price' => $price,
                'buy_price' => $price,
                'weight' => (float) ($skuData['package_weight'] ?? 0),
                'is_active' => strtolower((string) ($skuData['Status'] ?? 'active')) === 'active',
            ];

            $variant['media'] = $this->variantMedia($skuData['Images'] ?? []);
            if (empty($variant['media'])) {
                unset($variant['media']);
            }

            $internal['variants'][] = $variant;
        }

        if (empty($internal['variants'])) {
            $internal['variants'][] = [
                'sku' => 'LZ-' . ($lazadaProduct['item_id'] ?? uniqid()),
                'sell_price' => 0,
                'is_active' => true,
            ];
        }

        $internal['sku'] = $internal['variants'][0]['sku'] ?? null;

        return $internal;
    }

    /** Gambar per-SKU Lazada → struktur media internal. */
    protected function variantMedia(array $images): array
    {
        $media = [];
        foreach (array_values($images) as $idx => $url) {
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

    protected function resolveCategoryId(string $shopId, $lazadaCategoryId)
    {

        $fallback = function () {
            $id = DB::table('categories')
                ->where('name', 'Belum Dikategorikan')
                ->value('id');

            if ($id) {
                return $id;
            }

            return DB::table('categories')->insertGetId([
                'name' => 'Belum Dikategorikan',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        if (! $lazadaCategoryId) {
            return $fallback();
        }

        $channelId = DB::table('channel_shops')->where('shop_id', $shopId)->value('channel_id');
        if (! $channelId) {
            return $fallback();
        }

        $categoryId = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $lazadaCategoryId)
            ->value('category_channel_mappings.category_id');

        return $categoryId ?: $fallback();
    }
}
