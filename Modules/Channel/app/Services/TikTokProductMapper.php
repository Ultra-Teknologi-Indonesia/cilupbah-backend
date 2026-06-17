<?php

namespace Modules\Channel\Services;

class TikTokProductMapper
{
    public function map(array $internalProduct, array $uploadedImageIds = [], array $config = []): array
    {

        $categoryId = $config['category_id'] ?? '600048';
        $warehouseId = $config['warehouse_id'] ?? '7646426075561690887';

        $attributes = $config['attributes'] ?? [];

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
                'value' => (string)(($internalProduct['weight'] ?? null) ?: 1.0),
                'unit' => 'KILOGRAM'
            ],
            'package_dimensions' => [
                'length' => (string)(int)(($internalProduct['length'] ?? null) ?: 10),
                'width' => (string)(int)(($internalProduct['width'] ?? null) ?: 10),
                'height' => (string)(int)(($internalProduct['height'] ?? null) ?: 10),
                'unit' => 'CENTIMETER'
            ],
            'product_attributes' => $attributes ?: [],
        ];

        // TikTok membatasi main_images maksimal 9.
        if (!empty($uploadedImageIds)) {
            $payload['main_images'] = array_map(function ($uri) {
                return ['uri' => $uri];
            }, array_slice(array_values($uploadedImageIds), 0, 9));
        }

        // Video produk (id dari files/upload).
        if (!empty($config['video_id'])) {
            $payload['video'] = ['id' => $config['video_id']];
        }

        if (!empty($internalProduct['brand_id'])) {

        }

        if (!empty($internalProduct['variants'])) {
            $variantCount = count($internalProduct['variants']);
            $skus = [];
            foreach (array_values($internalProduct['variants']) as $idx => $variant) {
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

                $salesAttributes = [];
                if (!empty($variant['options'])) {
                    foreach ($variant['options'] as $option) {
                        $attrName  = strtolower($option['attribute_name'] ?? '');
                        $attrId    = $salesAttrIdMap[$attrName]
                            ?? ($config['sales_attribute_id'] ?? '100000');
                        $salesAttributes[] = [
                            'attribute_id' => $attrId,
                            'custom_value' => $option['value'],
                        ];
                    }
                } elseif ($variantCount > 1) {
                    // Multi-SKU tanpa variasi → sintesis sales attribute default agar
                    // TikTok bisa membedakan SKU (hindari error "sale attribute name
                    // or attribute ID should not be empty").
                    $salesAttributes[] = [
                        'attribute_name' => 'Tipe',
                        'custom_value' => ($variant['sku'] ?? '') ?: ('Varian ' . ($idx + 1)),
                    ];
                }

                if (!empty($salesAttributes)) {
                    // Foto per-varian → lekatkan ke nilai sales attribute pertama.
                    if (!empty($variant['image_uri'])) {
                        $salesAttributes[0]['sku_img'] = ['uri' => $variant['image_uri']];
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
