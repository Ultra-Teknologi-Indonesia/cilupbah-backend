<?php

namespace Modules\Channel\Services;

class TikTokProductMapper
{
    public function map(array $internalProduct, array $uploadedImageIds = [], array $config = []): array
    {
        // 600048 = "Botol Air" V2 category with Warna (100000) SALES_PROPERTY – works in ID sandbox
        $categoryId = $config['category_id'] ?? '600048';
        $warehouseId = $config['warehouse_id'] ?? '7646426075561690887';
        // Default empty; callers can pass product_attributes via $config if the category requires them
        $attributes = $config['attributes'] ?? [];

        // Map internal attribute names to TikTok SALES_PROPERTY IDs for category 802952
        $salesAttrIdMap = [
            'warna'   => '100000',
            'color'   => '100000',
            'ukuran'  => '100007',
            'size'    => '100007',
            'varian'  => '100000',
            'variasi' => '100000',
        ];

        $payload = [
            'save_mode' => 'LISTING',
            'title' => $internalProduct['name'],
            'description' => $internalProduct['description'] ?? '',
            'category_version' => $config['category_version'] ?? 'v2',
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
            'product_attributes' => $attributes ?: [],
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
                        $attrName  = strtolower($option['attribute_name'] ?? '');
                        $attrId    = $salesAttrIdMap[$attrName]
                            ?? ($config['sales_attribute_id'] ?? '100000');
                        $salesAttributes[] = [
                            'attribute_id' => $attrId,
                            'custom_value' => $option['value'],
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
