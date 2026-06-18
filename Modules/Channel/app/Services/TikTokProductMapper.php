<?php

namespace Modules\Channel\Services;

class TikTokProductMapper
{
    public function map(array $internalProduct, array $uploadedImageIds = [], array $config = []): array
    {
        $titleLen = mb_strlen(trim((string)($internalProduct['name'] ?? '')));
        if ($titleLen < 25 || $titleLen > 255) {
            throw new \RuntimeException(
                "Nama produk harus 25–255 karakter untuk TikTok (saat ini {$titleLen}).",
                422
            );
        }

        $categoryId = $config['category_id'] ?? '600048';
        $warehouseId = $config['warehouse_id'] ?? '7646426075561690887';

        $attributes = $config['attributes'] ?? [];
        $isUpdate = ($config['mode'] ?? 'create') === 'update';

        $salesAttributeMap = $config['sales_attribute_map'] ?? [];
        $salesNameMap = $config['sales_attribute_name_map'] ?? [];
        $salesIdToName = $config['sales_attribute_id_to_name'] ?? [];

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

        if (!empty($uploadedImageIds)) {
            $payload['main_images'] = array_map(function ($uri) {
                return ['uri' => $uri];
            }, array_slice(array_values($uploadedImageIds), 0, 9));
        }

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

                if (!empty($variant['external_sku_id'])) {
                    $sku['id'] = (string) $variant['external_sku_id'];
                }

                $salesAttributes = [];
                if (!empty($variant['sales_attributes'])) {

                    $salesAttributes = $this->normalizeSalesAttributes($variant['sales_attributes']);
                } elseif (!empty($variant['options'])) {
                    foreach ($variant['options'] as $option) {
                        $optAttrId = $option['attribute_id'] ?? null;
                        $attrName = strtolower($option['attribute_name'] ?? '');

                        $resolvedId = ($optAttrId && isset($salesAttributeMap[$optAttrId]))
                            ? (string) $salesAttributeMap[$optAttrId]
                            : ($salesNameMap[$attrName] ?? null);

                        $entry = ['custom_value' => $option['value']];
                        if ($resolvedId) {
                            $entry['id'] = $resolvedId;
                            $entry['name'] = $salesIdToName[$resolvedId] ?? $option['attribute_name'] ?? '';
                        } else {
                            $displayName = $option['attribute_name'] ?? '';
                            if ($displayName === '') {
                                continue;
                            }
                            $entry['name'] = $displayName;
                        }
                        $salesAttributes[] = $entry;
                    }
                } elseif ($variantCount > 1) {

                    $salesAttributes[] = [
                        'name' => 'Tipe',
                        'custom_value' => ($variant['sku'] ?? '') ?: ('Varian ' . ($idx + 1)),
                    ];
                }

                $salesAttributes = array_values(array_filter(
                    $salesAttributes,
                    fn ($attr) => ($attr['id'] ?? '') !== '' || ($attr['name'] ?? '') !== ''
                ));

                if ($isUpdate && $variantCount > 1) {
                    $hasUsableId = !empty(array_filter(
                        $salesAttributes,
                        fn ($attr) => ($attr['id'] ?? '') !== ''
                    ));
                    if (!$hasUsableId) {
                        throw new \RuntimeException(
                            "Tidak bisa update SKU '" . ($variant['sku'] ?? ('Varian ' . ($idx + 1)))
                            . "' ke TikTok: attribute_id sales attribute tidak diketahui. "
                            . "Lengkapi atribut variasi produk lalu upload ulang.",
                            422
                        );
                    }
                }

                if (!empty($salesAttributes)) {

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

    private function normalizeSalesAttributes(array $salesAttributes): array
    {
        $normalized = [];

        foreach ($salesAttributes as $attr) {
            $attributeId   = (string) ($attr['attribute_id'] ?? $attr['id'] ?? '');
            $attributeName = (string) ($attr['attribute_name'] ?? $attr['name'] ?? '');
            $customValue   = (string) ($attr['custom_value'] ?? $attr['value'] ?? $attr['value_name'] ?? '');

            $entry = ['custom_value' => $customValue];
            if ($attributeId !== '') {
                $entry['id'] = $attributeId;
            }
            if ($attributeName !== '') {
                $entry['name'] = $attributeName;
            }
            if (!empty($attr['sku_img'])) {
                $entry['sku_img'] = $attr['sku_img'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }
}
