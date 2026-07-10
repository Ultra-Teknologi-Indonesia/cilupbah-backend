<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;
use Modules\Channel\Support\DescriptionFormatter;

class WooCommerceProductMapper
{
    public function map(array $product, array $imageUrls = [], array $config = []): array
    {
        $categoryId = $this->resolveChannelCategoryExternalId($product['category_id'] ?? null, $config);

        $variants = array_values(array_filter(
            $product['variants'] ?? [],
            fn ($v) => ! empty($v['sku']) && (! array_key_exists('is_active', $v) || $v['is_active'])
        ));

        $payload = [
            'name' => $product['name'] ?? 'Produk',
            'description' => DescriptionFormatter::toHtml($product['description'] ?? '') ?: ($product['name'] ?? ''),
            'images' => $imageUrls ? array_map(fn ($url) => ['src' => $url], array_values($imageUrls)) : null,
            'categories' => $categoryId !== null ? [['id' => (int) $categoryId]] : null,
        ];

        if (count($variants) > 1) {
            $payload['type'] = 'variable';
            [$attributes, $variations] = $this->buildVariations($variants);
            $payload['attributes'] = $attributes;
        } else {
            $first = $variants[0] ?? [];
            $payload['type'] = 'simple';
            $payload['sku'] = $first['sku'] ?? ($product['sku'] ?? null);
            $payload['regular_price'] = (string) ($first['sell_price'] ?? 0);
            $payload['manage_stock'] = true;
            $payload['stock_quantity'] = (int) ($first['stock'] ?? 0);
        }

        $result = array_filter($payload, fn ($v) => $v !== null);

        if (! empty($variations)) {
            $result['_variations'] = $variations;
        }

        return $result;
    }

    protected function buildVariations(array $variants): array
    {
        $optionsByAttr = [];
        $variations = [];

        foreach ($variants as $variant) {
            $variationAttributes = [];

            foreach ($variant['options'] ?? [] as $opt) {
                $name = $opt['name'] ?? $opt['attribute_name'] ?? null;
                $value = $opt['value'] ?? null;
                if (! $name || $value === null || $value === '') {
                    continue;
                }
                $optionsByAttr[$name][(string) $value] = true;
                $variationAttributes[] = ['name' => (string) $name, 'option' => (string) $value];
            }

            $variation = [
                'sku' => $variant['sku'],
                'regular_price' => (string) ($variant['sell_price'] ?? 0),
                'manage_stock' => true,
                'stock_quantity' => (int) ($variant['stock'] ?? 0),
            ];

            if ($variationAttributes) {
                $variation['attributes'] = $variationAttributes;
            }

            if (! empty($variant['image_url'])) {
                $variation['image'] = ['src' => $variant['image_url']];
            }

            $variations[] = $variation;
        }

        $attributes = [];
        $position = 0;
        foreach ($optionsByAttr as $name => $values) {
            $attributes[] = [
                'name' => (string) $name,
                'position' => $position++,
                'visible' => true,
                'variation' => true,
                'options' => array_map('strval', array_keys($values)),
            ];
        }

        if (empty($attributes)) {
            $attributes[] = [
                'name' => 'Variasi',
                'position' => 0,
                'visible' => true,
                'variation' => true,
                'options' => array_values(array_map(fn ($v) => (string) $v['sku'], $variants)),
            ];

            foreach ($variations as $idx => &$variation) {
                $variation['attributes'] = [['name' => 'Variasi', 'option' => (string) $variants[$idx]['sku']]];
            }
            unset($variation);
        }

        return [$attributes, $variations];
    }

    protected function resolveChannelCategoryExternalId($categoryId, array $config): ?string
    {
        if ($categoryId) {
            $channelId = DB::table('channels')->where('code', 'woocommerce')->value('id');

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
