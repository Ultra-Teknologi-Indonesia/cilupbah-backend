<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

/**
 * Pemetaan produk Lazada (/products/get) → skema internal (pola TikTokToInternalProductMapper).
 * Produk hasil download masuk berstatus 'download' (belum Master).
 */
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

            $internal['variants'][] = [
                'sku' => $sku,
                'sell_price' => $price,
                'buy_price' => $price,
                'weight' => (float) ($skuData['package_weight'] ?? 0),
                'is_active' => strtolower((string) ($skuData['Status'] ?? 'active')) === 'active',
            ];
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

    /**
     * Kategori Lazada → category_id internal via category_channel_mappings.
     * Fallback ke kategori root pertama (pola TikTok).
     */
    protected function resolveCategoryId(string $shopId, $lazadaCategoryId)
    {
        $fallback = fn () => DB::table('categories')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->value('id');

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
