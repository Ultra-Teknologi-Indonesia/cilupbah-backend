<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Support\TikTokImageUrl;

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

            'status' => 'master',
            'is_from_channel' => true,
            'verified_at' => now(),
        ];

        if (isset($tiktokProduct['package_dimensions'])) {
            $internal['length'] = $tiktokProduct['package_dimensions']['length'] ?? 0;
            $internal['width'] = $tiktokProduct['package_dimensions']['width'] ?? 0;
            $internal['height'] = $tiktokProduct['package_dimensions']['height'] ?? 0;
        }

        if (isset($tiktokProduct['package_weight'])) {

            $w = (float) ($tiktokProduct['package_weight']['value'] ?? 0);
            $unit = strtoupper((string) ($tiktokProduct['package_weight']['unit'] ?? 'KILOGRAM'));
            $internal['weight'] = $unit === 'GRAM' ? $w / 1000 : $w;
        }

        $internal['variants'] = [];
        $internal['media'] = [];

        $seenUrls = [];
        if (!empty($tiktokProduct['main_images'])) {
            foreach ($tiktokProduct['main_images'] as $idx => $img) {
                $url = $this->normalizeImageUrl($img['urls'][0] ?? $img['uri'] ?? '');
                if (!$url || isset($seenUrls[$url])) {
                    continue;
                }
                $seenUrls[$url] = true;
                $internal['media'][] = [
                    'media_type' => 'image',
                    'url' => $url,
                    'is_primary' => count($internal['media']) === 0,
                    'sort_order' => count($internal['media']),
                ];
            }
        }

        if (!empty($tiktokProduct['video']) && !empty($tiktokProduct['video']['url'])) {
            $videoUrl = $this->normalizeImageUrl($tiktokProduct['video']['url']);
            if ($videoUrl) {
                $internal['media'][] = [
                    'media_type' => 'video',
                    'url' => $videoUrl,
                    'is_primary' => false,
                    'sort_order' => count($internal['media']),
                ];
            }
        }

        $variationTypeOrder = [];
        $mainImageUrls = array_keys($seenUrls);

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

                $variant = [
                    'sku' => $sku,
                    'sell_price' => $price,
                    'buy_price' => $price,
                    'weight' => $internal['weight'] ?? 0,
                    'is_active' => true,
                ];

                $options = [];
                foreach ($skuData['sales_attributes'] ?? [] as $attr) {
                    $name = $attr['name'] ?? null;
                    $value = $attr['value_name'] ?? null;
                    if (! $name || $value === null || $value === '') {
                        continue;
                    }
                    $options[] = ['name' => $name, 'value' => $value];
                    if (! in_array($name, $variationTypeOrder, true)) {
                        $variationTypeOrder[] = $name;
                    }
                }
                if ($options) {
                    $variant['options'] = $options;
                }

                $skuImg = $this->extractSkuImage($skuData);
                if ($skuImg && !in_array($skuImg, $mainImageUrls, true)) {
                    $variant['media'] = [[
                        'media_type' => 'image',
                        'url' => $skuImg,
                        'is_primary' => true,
                        'sort_order' => 0,
                    ]];
                } elseif (! $skuImg) {
                    Log::channel('warning')->info('TikTok SKU image not found', [
                        'sku' => $sku,
                        'product_title' => $tiktokProduct['title'] ?? null,
                        'sales_attributes_keys' => array_map(
                            fn ($a) => array_keys($a),
                            $skuData['sales_attributes'] ?? []
                        ),
                    ]);
                }

                $internal['variants'][] = $variant;
            }

            $internal['variation_types'] = array_map(
                fn ($name, $i) => ['name' => $name, 'sort_order' => $i],
                $variationTypeOrder,
                array_keys($variationTypeOrder)
            );
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

    protected function extractSkuImage(array $skuData): ?string
    {

        foreach ($skuData['sales_attributes'] ?? [] as $attr) {
            $ref = $this->pickImageRef($attr['sku_img'] ?? null);
            if ($ref) {
                return $this->normalizeImageUrl($ref);
            }
        }

        $ref = $this->pickImageRef($skuData['representative_sku_image'] ?? null);
        if ($ref) {
            return $this->normalizeImageUrl($ref);
        }

        $directImg = $skuData['sku_img'] ?? null;
        if (is_string($directImg) && $directImg !== '') {
            return $this->normalizeImageUrl($directImg);
        }
        $ref = $this->pickImageRef(is_array($directImg) ? $directImg : null);
        if ($ref) {
            return $this->normalizeImageUrl($ref);
        }

        return null;
    }

    protected function pickImageRef(?array $img): ?string
    {
        if (! $img) {
            return null;
        }

        return $img['url_list'][0]
            ?? $img['thumb_url_list'][0]
            ?? $img['urls'][0]
            ?? $img['uri']
            ?? null;
    }

    protected function normalizeImageUrl(?string $url): ?string
    {
        return TikTokImageUrl::ensureFetchable($url);
    }

    protected function resolveCategoryId(string $shopId, ?string $tiktokCategoryId): int
    {
        $fallback = function () {
            $id = DB::table('categories')
                ->where('name', 'Belum Dikategorikan')
                ->value('id');

            if ($id) {
                return (int) $id;
            }

            return (int) DB::table('categories')->insertGetId([
                'name' => 'Belum Dikategorikan',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        };

        if (!$tiktokCategoryId) {
            return $fallback();
        }

        $channelId = DB::table('channel_shops')
            ->where('shop_id', $shopId)
            ->value('channel_id');

        if (!$channelId) {
            return $fallback();
        }

        $mappings = DB::table('category_channel_mappings')
            ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
            ->join('categories', 'categories.id', '=', 'category_channel_mappings.category_id')
            ->where('channel_categories.channel_id', $channelId)
            ->where('channel_categories.external_id', (string) $tiktokCategoryId)
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
