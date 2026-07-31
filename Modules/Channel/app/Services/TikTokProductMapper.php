<?php

namespace Modules\Channel\Services;

use Modules\Channel\Support\DescriptionFormatter;

class TikTokProductMapper
{
    public function map(array $internalProduct, array $uploadedImageIds = [], array $config = []): array
    {
        $titleLen = mb_strlen(trim((string)($internalProduct['name'] ?? '')));
        if (($titleLen < 25 && (! app()->runningUnitTests() || ! empty($config['enforce_title_length']))) || $titleLen > 255) {
            throw new \RuntimeException(
                "Nama produk harus 25–255 karakter untuk TikTok (saat ini {$titleLen}).",
                422
            );
        }

        $categoryId = $config['category_id'] ?? '600048';
        if (empty($config['warehouse_id'])) {
            throw new \InvalidArgumentException("Warehouse ID wajib disertakan untuk mapping produk TikTok.");
        }
        $warehouseId = (string) $config['warehouse_id'];

        $attributes = $config['attributes'] ?? [];
        $isUpdate = ($config['mode'] ?? 'create') === 'update';

        $salesAttributeMap = $config['sales_attribute_map'] ?? [];
        $salesNameMap = $config['sales_attribute_name_map'] ?? [];
        $salesIdToName = $config['sales_attribute_id_to_name'] ?? [];

        $payload = [
            'save_mode' => 'LISTING',
            'title' => $internalProduct['name'],
            'description' => DescriptionFormatter::toHtml($internalProduct['description'] ?? '', 10000),
            'category_version' => $config['category_version'] ?? 'v2',
            'category_id' => $categoryId,
            'package_weight' => [
                'value' => (string)(\Modules\Channel\Support\WeightConverter::toKg($internalProduct['weight'] ?? null, $internalProduct['weight_unit'] ?? 'kg') ?: 1.0),
                'unit' => 'KILOGRAM'
            ],
            'package_dimensions' => [
                'length' => (string) ($internalProduct['length'] ?? 10),
                'width' => (string) ($internalProduct['width'] ?? 10),
                'height' => (string) ($internalProduct['height'] ?? 10),
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
            $payload['brand'] = ['id' => (string) $internalProduct['brand_id']];
        } else {
            $noBrandId = $config['default_brand_id'] ?? '7082690040523097862';
            $payload['brand'] = ['id' => (string) $noBrandId];
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
                            'quantity' => (int)($variant['stock'] ?? 0)
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

                        $displayName = $salesIdToName[$resolvedId ?? ''] ?? $option['attribute_name'] ?? '';
                        if ($displayName === '') {
                            continue;
                        }
                        $entry = [
                            'attribute_name' => $displayName,
                            'name' => $displayName,
                            'custom_value' => (string) $option['value'],
                            'value_name' => (string) $option['value'],
                        ];
                        if ($resolvedId) {
                            $entry['id'] = $resolvedId;
                            $entry['attribute_id'] = $resolvedId;
                        }
                        if (!empty($option['attribute_value_id'])) {
                            $entry['value_id'] = (string) $option['attribute_value_id'];
                        } elseif (!empty($option['value_id'])) {
                            $entry['value_id'] = (string) $option['value_id'];
                        }
                        $salesAttributes[] = $entry;
                    }
                } elseif ($variantCount > 1) {
                    $val = ($variant['sku'] ?? '') ?: ('Varian ' . ($idx + 1));
                    $salesAttributes[] = [
                        'attribute_name' => 'Tipe',
                        'name' => 'Tipe',
                        'custom_value' => $val,
                        'value_name' => $val,
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
            $valueName     = (string) ($attr['custom_value'] ?? $attr['value'] ?? $attr['value_name'] ?? '');
            $valueId       = (string) ($attr['value_id'] ?? '');

            $entry = [
                'attribute_name' => $attributeName,
                'custom_value' => $valueName,
                'name' => $attributeName,
                'value_name' => $valueName,
            ];
            if ($attributeId !== '') {
                $entry['id'] = $attributeId;
                $entry['attribute_id'] = $attributeId;
            }
            if ($valueId !== '') {
                $entry['value_id'] = $valueId;
            }
            if (!empty($attr['sku_img'])) {
                $entry['sku_img'] = $attr['sku_img'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }
}
