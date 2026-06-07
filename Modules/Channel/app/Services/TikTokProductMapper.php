<?php

namespace Modules\Channel\Services;

class TikTokProductMapper
{
    public function map(array $internalProduct, array $uploadedImageIds = [], array $config = []): array
    {
        $categoryId = $config['category_id'] ?? '839824'; // fallback to default
        $warehouseId = $config['warehouse_id'] ?? '7646426075561690887';
        $attributes = $config['attributes'] ?? [
            ['id' => '100393', 'values' => [['id' => '1001182', 'name' => 'Polos']]],
            ['id' => '100400', 'values' => [['id' => '1001182', 'name' => 'Polos']]]
        ];

        $payload = [
            'save_mode' => 'LISTING', 
            'title' => $internalProduct['name'],
            'description' => $internalProduct['description'] ?? '',
            'category_version' => 'v2',
            'category_id' => $categoryId,
            'package_weight' => [
                'value' => (string)($internalProduct['weight'] ?: 1.0),
                'unit' => 'KILOGRAM'
            ],
            'package_dimensions' => [
                'length' => (string)(int)($internalProduct['length'] ?: 10),
                'width' => (string)(int)($internalProduct['width'] ?: 10),
                'height' => (string)(int)($internalProduct['height'] ?: 10),
                'unit' => 'CENTIMETER'
            ],
            'product_attributes' => $attributes,
        ];

        if (!empty($uploadedImageIds)) {
            $payload['main_images'] = array_map(function ($uri) {
                return ['uri' => $uri];
            }, $uploadedImageIds);
        }

        if (!empty($internalProduct['brand_id'])) {
            // $payload['brand_id'] = $internalProduct['brand_id'];
        }

        if (!empty($internalProduct['variants'])) {
            $skus = [];
            foreach ($internalProduct['variants'] as $variant) {
                $sku = [
                    'seller_sku' => $variant['sku'] ?? '',
                    'price' => [
                        'amount' => (string)$variant['sell_price'],
                        'currency' => 'IDR'
                    ],
                    'inventory' => [
                        [
                            'warehouse_id' => $warehouseId, 
                            'quantity' => (int)($variant['stock'] ?? 100) 
                        ]
                    ]
                ];

                if (!empty($variant['options'])) {
                    $salesAttributes = [];
                    foreach ($variant['options'] as $option) {
                        $salesAttributes[] = [
                            'attribute_id' => $config['sales_attribute_id'] ?? '100000',
                            'custom_value' => $option['value']
                        ];
                    }
                    $sku['sales_attributes'] = $salesAttributes;
                }

                $skus[] = $sku;
            }
            $payload['skus'] = $skus;
        }

        return $payload;
    }
}
