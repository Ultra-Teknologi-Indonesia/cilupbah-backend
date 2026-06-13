<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\DB;

class LazadaProductMapper
{

    public function map(array $product, array $imageUrls = [], array $config = []): array
    {
        $skus = [];

        foreach ($product['variants'] ?? [] as $variant) {
            if (empty($variant['sku'])) {
                continue;
            }

            $skus[] = array_filter([
                'SellerSku' => $variant['sku'],
                'quantity' => 0, 
                'price' => (float) ($variant['sell_price'] ?? 0),
                'package_weight' => (float) ($variant['weight'] ?? $product['weight'] ?? 0.1) ?: 0.1,
                'package_length' => (float) ($product['length'] ?? 0) ?: null,
                'package_width' => (float) ($product['width'] ?? 0) ?: null,
                'package_height' => (float) ($product['height'] ?? 0) ?: null,
            ], fn ($v) => $v !== null);
        }

        return [
            'Request' => [
                'Product' => array_filter([
                    'PrimaryCategory' => $this->resolveChannelCategoryId($product['category_id'] ?? null, $config),
                    'Images' => $imageUrls ? ['Image' => array_values($imageUrls)] : null,
                    'Attributes' => [
                        'name' => $product['name'] ?? 'Produk',
                        'description' => $product['description'] ?? ($product['name'] ?? ''),
                        'brand' => $config['brand'] ?? 'No Brand',
                    ],
                    'Skus' => ['Sku' => $skus],
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    protected function resolveChannelCategoryId($categoryId, array $config): ?string
    {
        if ($categoryId) {
            $channelId = DB::table('channels')->where('code', 'lazada')->value('id');

            $externalId = DB::table('category_channel_mappings')
                ->join('channel_categories', 'channel_categories.id', '=', 'category_channel_mappings.channel_category_id')
                ->where('category_channel_mappings.category_id', $categoryId)
                ->where('channel_categories.channel_id', $channelId)
                ->value('channel_categories.external_id');

            if ($externalId) {
                return (string) $externalId;
            }
        }

        $fallback = $config['primary_category'] ?? null;

        return $fallback !== null ? (string) $fallback : null;
    }
}
