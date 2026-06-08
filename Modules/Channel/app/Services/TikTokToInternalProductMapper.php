<?php

namespace Modules\Channel\Services;

class TikTokToInternalProductMapper
{
    public function map(array $tiktokProduct, string $shopId): array
    {
        $internal = [
            'category_id' => 1,
            'name' => $tiktokProduct['title'] ?? 'TikTok Product',
            'description' => $tiktokProduct['description'] ?? '',
            'condition' => 'NEW',
            'is_draft' => $tiktokProduct['status'] !== 'ACTIVATE',
            'is_active' => true,
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
}