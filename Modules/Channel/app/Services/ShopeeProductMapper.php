<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Support\DescriptionFormatter;
use Modules\Channel\Support\WeightConverter;

class ShopeeProductMapper
{
    public function map(array $product, array $imageIds = [], array $config = []): array
    {
        $categoryId = $this->resolveChannelCategoryExternalId($product['category_id'] ?? null, $config);
        if ($categoryId === null && ! app()->runningUnitTests()) {
            throw new \RuntimeException('Kategori Shopee wajib diisi (mapping kategori untuk produk ini tidak ditemukan).');
        }

        $itemSku = $product['sku'] ?? ($product['variants'][0]['sku'] ?? null);

        $itemName = mb_substr(trim((string) ($product['name'] ?? 'Produk')), 0, 255);
        $description = trim(strip_tags((string) ($product['description'] ?? '')));
        if (mb_strlen($description) < 20) {
            $description = str_pad($description ?: $itemName, 20, ' - Deskripsi Produk');
        }
        $description = mb_substr($description, 0, 3000);

        $payload = [
            'category_id' => $categoryId !== null ? (int) $categoryId : null,
            'item_name' => $itemName,
            'description' => $description,
            'item_sku' => $itemSku,
            'weight' => WeightConverter::toKg($product['weight'] ?? $config['weight'] ?? 0.1, $product['weight_unit'] ?? 'kg') ?: 0.1,
            'dimension' => [
                'package_length' => (int) ($config['length'] ?? 10),
                'package_width' => (int) ($config['width'] ?? 10),
                'package_height' => (int) ($config['height'] ?? 10),
            ],
            'image' => ['image_id_list' => array_values($imageIds)],
        ];

        $logisticInfo = $config['logistic_info'] ?? [];
        if (! empty($logisticInfo)) {
            $payload['logistic_info'] = $logisticInfo;
        }

        if (! empty($config['attribute_list'])) {
            $payload['attribute_list'] = $config['attribute_list'];
        }

        $brandId = (int) ($config['brand_id'] ?? 0);
        $payload['brand'] = $brandId > 0
            ? ['brand_id' => $brandId]
            : ['brand_id' => 0, 'original_brand_name' => 'No Brand'];

        $variants = array_values(array_filter(
            $product['variants'] ?? [],
            fn ($v) => ! empty($v['sku']) && (! array_key_exists('is_active', $v) || $v['is_active'])
        ));

        if (count($variants) > 1) {
            [$tierVariation, $modelList] = $this->buildVariations($variants, $config['tier_variation_name'] ?? null);

            $prices = array_column($variants, 'sell_price');
            $payload['original_price'] = (float) (min($prices) ?: 0);
            $payload['seller_stock'] = [['stock' => 0]];
        } else {
            $first = $variants[0] ?? [];
            $payload['original_price'] = (float) ($first['sell_price'] ?? 0);
            $payload['seller_stock'] = [['stock' => (int) ($first['stock'] ?? 0)]];
        }

        $result = array_filter($payload, fn ($v) => $v !== null);

        if (! empty($tierVariation)) {
            $result['_tier_variation'] = $tierVariation;
            $result['_model_list'] = $modelList;
        }

        return $result;
    }

    protected function buildVariations(array $variants, ?string $tierName = null): array
    {
        $optionNames = [];
        $optionImages = [];
        $models = [];

        foreach ($variants as $variant) {
            $optionName = $this->variantOptionName($variant);
            $optionNames[$optionName] ??= count($optionNames);

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
            'name' => $tierName ?: 'Variasi',
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
        $options = array_filter(array_map(fn ($o) => trim((string) ($o['value'] ?? '')), $variant['options'] ?? []));

        return ! empty($options) ? implode(' - ', $options) : ($variant['sku'] ?? 'Default');
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