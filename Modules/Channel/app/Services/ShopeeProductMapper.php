<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Support\DescriptionFormatter;

/**
 * Internal Product → payload Shopee add_item (v2).
 *
 * Catatan verifikasi live: field image (image_id_list) butuh upload media lebih dulu;
 * struktur tier_variation/model_list & logistic_info per-region wajib dikonfirmasi di sandbox.
 */
class ShopeeProductMapper
{
    public function map(array $product, array $imageIds = [], array $config = []): array
    {
        $categoryId = $this->resolveChannelCategoryExternalId($product['category_id'] ?? null, $config);
        $itemSku = $product['sku'] ?? ($product['variants'][0]['sku'] ?? null);

        $payload = [
            'category_id' => $categoryId !== null ? (int) $categoryId : null,
            'item_name' => $product['name'] ?? 'Produk',
            'description' => DescriptionFormatter::toHtml($product['description'] ?? '') ?: ($product['name'] ?? ''),
            'item_sku' => $itemSku,
            'weight' => (float) ($product['weight'] ?? $config['weight'] ?? 0.1) ?: 0.1,
            'dimension' => [
                'package_length' => (int) ($product['length'] ?? $config['length'] ?? 10),
                'package_width' => (int) ($product['width'] ?? $config['width'] ?? 10),
                'package_height' => (int) ($product['height'] ?? $config['height'] ?? 10),
            ],
            'image' => ['image_id_list' => array_values($imageIds)],
            'logistic_info' => $config['logistic_info'] ?? [],
        ];

        if (! empty($config['brand_id'])) {
            $payload['brand'] = ['brand_id' => (int) $config['brand_id']];
        }

        $variants = array_values(array_filter(
            $product['variants'] ?? [],
            fn ($v) => ! empty($v['sku']) && (! array_key_exists('is_active', $v) || $v['is_active'])
        ));

        if (count($variants) > 1) {
            [$payload['tier_variation'], $payload['model_list']] = $this->buildVariations($variants);
        } else {
            $first = $variants[0] ?? [];
            $payload['price_info'] = [['current_price' => (float) ($first['sell_price'] ?? 0)]];
            $payload['stock_info_v2'] = [['stock_type' => 1, 'stock' => (int) ($first['stock'] ?? 0)]];
        }

        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Bangun tier_variation (1 tier dari opsi pertama tiap varian) + model_list.
     *
     * @return array{0: array, 1: array}
     */
    protected function buildVariations(array $variants): array
    {
        $optionNames = [];
        $optionImages = [];
        $models = [];

        foreach ($variants as $variant) {
            $optionName = $this->variantOptionName($variant);
            $optionNames[$optionName] ??= count($optionNames);

            // Gambar per varian dipasang ke opsi tier (image_id hasil upload media).
            if (! isset($optionImages[$optionName]) && ! empty($variant['image_id'])) {
                $optionImages[$optionName] = $variant['image_id'];
            }

            $models[] = [
                'tier_index' => [$optionNames[$optionName]],
                'model_sku' => $variant['sku'],
                'original_price' => (float) ($variant['sell_price'] ?? 0),
                'seller_stock' => [['stock' => (int) ($variant['stock'] ?? 0)]],
            ];
        }

        $tierVariation = [[
            'name' => 'Variasi',
            'option_list' => array_map(function ($name) use ($optionImages) {
                $option = ['option' => $name];
                if (! empty($optionImages[$name])) {
                    $option['image'] = ['image_id' => $optionImages[$name]];
                }

                return $option;
            }, array_keys($optionNames)),
        ]];

        return [$tierVariation, $models];
    }

    protected function variantOptionName(array $variant): string
    {
        $option = $variant['options'][0]['value'] ?? null;

        return $option !== null && $option !== '' ? (string) $option : ($variant['sku'] ?? 'Default');
    }

    protected function resolveChannelCategoryExternalId($categoryId, array $config): ?string
    {
        if ($categoryId) {
            $channelId = DB::table('channels')->where('code', 'shopee')->value('id');

            $externalId = DB::table('category_channel_mappings')
                ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
                ->where('category_channel_mappings.category_id', $categoryId)
                ->where('channel_categories.channel_id', $channelId)
                ->value('channel_categories.external_id');

            if ($externalId) {
                return (string) $externalId;
            }
        }

        return isset($config['category_id']) ? (string) $config['category_id'] : null;
    }
}
