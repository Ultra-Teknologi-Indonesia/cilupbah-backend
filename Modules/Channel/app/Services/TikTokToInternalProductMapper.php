<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

class TikTokToInternalProductMapper
{
    public function map(array $tiktokProduct, string $shopId): array
    {
        $tiktokCategoryId = $tiktokProduct['category_id']
            ?? ($tiktokProduct['categories'][0]['id'] ?? null);

        $internal = [
            'category_id' => $this->resolveCategoryId($shopId, $tiktokCategoryId),
            'name' => $tiktokProduct['title'] ?? 'TikTok Product',
            'description' => $tiktokProduct['description'] ?? '',
            'condition' => 'NEW',
            'is_draft' => ($tiktokProduct['status'] ?? null) !== 'ACTIVATE',
            'is_active' => true,
            // Produk hasil download masuk sebagai 'download' (belum jadi Master).
            'status' => 'download',
        ];

        if (isset($tiktokProduct['package_dimensions'])) {
            $internal['length'] = $tiktokProduct['package_dimensions']['length'] ?? 0;
            $internal['width'] = $tiktokProduct['package_dimensions']['width'] ?? 0;
            $internal['height'] = $tiktokProduct['package_dimensions']['height'] ?? 0;
        }

        if (isset($tiktokProduct['package_weight'])) {
            $internal['weight'] = $tiktokProduct['package_weight']['value'] ?? 0;
        }

        $internal['variants'] = [];
        $internal['media'] = [];

        if (!empty($tiktokProduct['main_images'])) {
            foreach ($tiktokProduct['main_images'] as $idx => $img) {
                $internal['media'][] = [
                    'media_type' => 'image',
                    'url' => $img['urls'][0] ?? $img['uri'] ?? '',
                    'is_primary' => $idx === 0,
                    'sort_order' => $idx,
                ];
            }
        }

        if (!empty($tiktokProduct['skus'])) {
            foreach ($tiktokProduct['skus'] as $skuData) {
                $sku = !empty($skuData['seller_sku'])
                    ? $skuData['seller_sku']
                    : ('TK-' . $skuData['id']);

                $price = 0;
                if (isset($skuData['price']['tax_exclusive_price'])) {
                    $price = $skuData['price']['tax_exclusive_price'];
                }

                $qty = 0;
                if (!empty($skuData['inventory'])) {
                    $qty = $skuData['inventory'][0]['quantity'] ?? 0;
                }

                $internal['variants'][] = [
                    'sku' => $sku,
                    'sell_price' => $price,
                    'buy_price' => $price,
                    'weight' => $internal['weight'] ?? 0,
                    'is_active' => true,
                ];
            }
        } else {
            $internal['variants'][] = [
                'sku' => 'TK-' . $tiktokProduct['id'],
                'sell_price' => 0,
                'is_active' => true,
            ];
        }

        $internal['sku'] = $internal['variants'][0]['sku'] ?? null;

        return $internal;
    }

    /**
     * Resolve kategori TikTok ke category_id internal via category_channel_mappings.
     * Fallback ke 1 (kategori root) jika belum ada mapping.
     */
    protected function resolveCategoryId(string $shopId, ?string $tiktokCategoryId): int
    {
        $fallback = fn () => (int) DB::table('categories')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->value('id');

        if (!$tiktokCategoryId) {
            return $fallback();
        }

        $channelId = DB::table('channel_shops')
            ->where('shop_id', $shopId)
            ->value('channel_id');

        if (!$channelId) {
            return $fallback();
        }

        // Lookup via channel_categories → category_channel_mappings
        $categoryId = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $tiktokCategoryId)
            ->value('category_channel_mappings.category_id');

        return $categoryId ? (int) $categoryId : $fallback();
    }
}