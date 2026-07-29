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
                'package_length' => max(1, (int) ($product['length'] ?? $config['length'] ?? 10)),
                'package_width' => max(1, (int) ($product['width'] ?? $config['width'] ?? 10)),
                'package_height' => max(1, (int) ($product['height'] ?? $config['height'] ?? 10)),
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

        if (! empty($config['video_upload_id'])) {
            $payload['video_upload_id'] = array_values((array) $config['video_upload_id']);
        }

        $variants = array_values(array_filter(
            $product['variants'] ?? [],
            fn ($v) => ! empty($v['sku']) && (! array_key_exists('is_active', $v) || $v['is_active'])
        ));

        $standardiseTierVariation = null;
        $modelList = null;

        if (count($variants) > 1) {
            [$standardiseTierVariation, $modelList] = $this->buildVariations(
                $variants,
                $config['tier_variation_name'] ?? null,
                $config['tier_variation_names'] ?? []
            );

            $prices = array_column($variants, 'sell_price');
            $payload['original_price'] = (float) (min($prices) ?: 0);
            $payload['seller_stock'] = [['stock' => 0]];
        } else {
            $first = $variants[0] ?? [];
            $payload['original_price'] = (float) ($first['sell_price'] ?? 0);
            $payload['seller_stock'] = [['stock' => (int) ($first['stock'] ?? 0)]];
        }

        $result = array_filter($payload, fn ($v) => $v !== null);

        if (! empty($standardiseTierVariation)) {
            $result['_standardise_tier_variation'] = $standardiseTierVariation;
            $result['_model_list'] = $modelList;
        }

        return $result;
    }

    protected function buildVariations(array $variants, ?string $primaryName = null, array $namesByAttr = []): array
    {
        $attrOrder = [];
        foreach ($variants as $variant) {
            foreach ($variant['options'] ?? [] as $opt) {
                $attrId = $opt['attribute_id'] ?? null;
                $value = trim((string) ($opt['value'] ?? ''));
                if ($attrId === null || $value === '') {
                    continue;
                }
                if (! array_key_exists($attrId, $attrOrder)) {
                    $attrOrder[$attrId] = count($attrOrder);
                }
            }
        }

        $tierAttrIds = array_slice(array_keys($attrOrder), 0, 2);

        if (empty($tierAttrIds)) {
            return $this->buildSingleTier($variants, $primaryName);
        }

        $tierOptions = array_fill(0, count($tierAttrIds), []);
        $tierOptionImages = [];
        $models = [];

        foreach ($variants as $variant) {
            $valuesByAttr = [];
            foreach ($variant['options'] ?? [] as $opt) {
                $aId = $opt['attribute_id'] ?? null;
                $v = trim((string) ($opt['value'] ?? ''));
                if ($aId !== null && $v !== '') {
                    $valuesByAttr[$aId] = $v;
                }
            }

            $tierIndex = [];
            foreach ($tierAttrIds as $tierPos => $attrId) {
                $value = $valuesByAttr[$attrId] ?? '-';
                if (! array_key_exists($value, $tierOptions[$tierPos])) {
                    $tierOptions[$tierPos][$value] = count($tierOptions[$tierPos]);
                }
                $tierIndex[] = $tierOptions[$tierPos][$value];

                if ($tierPos === 0 && ! isset($tierOptionImages[$value]) && ! empty($variant['image_id'])) {
                    $tierOptionImages[$value] = $variant['image_id'];
                }
            }

            $models[] = [
                'tier_index' => $tierIndex,
                'model_sku' => $variant['sku'],
                'original_price' => (float) ($variant['sell_price'] ?? 0),
                'seller_stock' => [['stock' => (int) ($variant['stock'] ?? 0)]],
            ];
        }

        $standardise = [];
        foreach ($tierAttrIds as $tierPos => $attrId) {
            $optionList = [];
            foreach (array_keys($tierOptions[$tierPos]) as $value) {
                $option = ['variation_option_id' => 0, 'variation_option_name' => (string) $value];
                if ($tierPos === 0 && ! empty($tierOptionImages[$value])) {
                    $option['image_id'] = $tierOptionImages[$value];
                }
                $optionList[] = $option;
            }

            $name = $namesByAttr[$attrId]
                ?? ($tierPos === 0 ? ($primaryName ?: 'Variasi') : 'Variasi ' . ($tierPos + 1));

            $standardise[] = [
                'variation_id' => 0,
                'variation_name' => $name,
                'variation_option_list' => $optionList,
            ];
        }

        $this->guardVariationLimits($standardise, $models);

        return [$standardise, $models];
    }

    protected function buildSingleTier(array $variants, ?string $tierName = null): array
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

        $standardise = [[
            'variation_id' => 0,
            'variation_name' => $tierName ?: 'Variasi',
            'variation_option_list' => array_map(function ($name) use ($optionImages) {
                $option = ['variation_option_id' => 0, 'variation_option_name' => (string) $name];
                if (! empty($optionImages[$name])) {
                    $option['image_id'] = $optionImages[$name];
                }

                return $option;
            }, array_keys($optionNames)),
        ]];

        $this->guardVariationLimits($standardise, $models);

        return [$standardise, $models];
    }

    protected function guardVariationLimits(array $standardise, array $models): void
    {
        foreach ($standardise as $i => $tier) {
            $count = count($tier['variation_option_list']);
            if ($count > 20) {
                throw new \RuntimeException(
                    'Variasi Shopee tingkat ' . ($i + 1) . " memiliki {$count} pilihan, melebihi batas maksimal 20 pilihan per tingkat. "
                    . 'Susun variasi menjadi 2 tingkat (mis. Warna × Tipe) atau pecah produk.'
                );
            }
        }

        if (count($models) > 50) {
            throw new \RuntimeException(
                'Produk memiliki ' . count($models) . ' varian, melebihi batas maksimal 50 varian per produk Shopee. '
                . 'Pecah menjadi beberapa produk terpisah.'
            );
        }
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