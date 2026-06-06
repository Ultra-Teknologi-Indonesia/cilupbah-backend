<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ProductService
{
    public function upsertFromChannel(array $data)
    {
        $sku = $data['sku'] ?? ($data['variants'][0]['sku'] ?? null);
        if ($sku) {
            $existingProduct = DB::table('products')->where('sku', $sku)->first();
            $productId = $existingProduct ? $existingProduct->id : null;
            
            if (!$productId) {
                $variant = DB::table('product_variants')->where('sku', $sku)->first();
                if ($variant) {
                    $productId = $variant->product_id;
                }
            }
            
            if ($productId) {
                return $this->updateProduct($productId, $data);
            }
        }
        
        return $this->createProduct($data);
    }

    public function updateProduct(string $productId, array $data)
    {
        return DB::transaction(function () use ($productId, $data) {
            $productData = Arr::only($data, [
                'name', 'description', 'weight', 'length', 'width', 'height', 'is_active',
                'channel_shop_id', 'source'
            ]);
            
            if (!empty($productData)) {
                $productData['updated_at'] = now();
                DB::table('products')->where('id', $productId)->update($productData);
            }

            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    if (empty($variant['sku'])) continue;
                    
                    $variantData = Arr::only($variant, [
                        'sell_price', 'is_active'
                    ]);
                    $variantData['updated_at'] = now();

                    $existingVariant = DB::table('product_variants')
                        ->where('product_id', $productId)
                        ->where('sku', $variant['sku'])
                        ->first();
                    
                    if ($existingVariant) {
                        DB::table('product_variants')->where('id', $existingVariant->id)->update($variantData);
                    } else {
                        DB::table('product_variants')->insert(array_merge($variantData, [
                            'id' => (string) Str::orderedUuid(),
                            'product_id' => $productId,
                            'sku' => $variant['sku'],
                            'created_at' => now(),
                        ]));
                    }
                }
            }

            return $productId;
        });
    }

    public function createProduct(array $data)
    {
        return DB::transaction(function () use ($data) {
            $productData = Arr::only($data, [
                'category_id', 'brand_id', 'name', 'description', 
                'weight', 'length', 'width', 'height', 'is_active',
                'channel_shop_id', 'source'
            ]);
            
            $productId = (string) Str::orderedUuid();
            DB::table('products')->insert(array_merge($productData, [
                'id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            if (!empty($data['specifications'])) {
                $specs = array_map(function ($spec) use ($productId) {
                    return [
                        'product_id' => $productId,
                        'attribute_id' => $spec['attribute_id'],
                        'attribute_option_id' => $spec['attribute_option_id'] ?? null,
                        'text_value' => $spec['text_value'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $data['specifications']);
                DB::table('product_specifications')->insert($specs);
            }

            if (!empty($data['media'])) {
                $media = array_map(function ($m) use ($productId) {
                    return [
                        'product_id' => $productId,
                        'variant_id' => null,
                        'media_type' => $m['media_type'] ?? 'image',
                        'url' => $m['url'],
                        'sort_order' => $m['sort_order'] ?? 0,
                        'is_primary' => $m['is_primary'] ?? false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $data['media']);
                DB::table('product_media')->insert($media);
            }

            if (!empty($data['variation_types'])) {
                $varTypes = array_map(function ($vt) use ($productId) {
                    return [
                        'product_id' => $productId,
                        'attribute_id' => $vt['attribute_id'],
                        'sort_order' => $vt['sort_order'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }, $data['variation_types']);
                DB::table('product_variation_types')->insert($varTypes);
            }

            if (!empty($data['variants'])) {
                foreach ($data['variants'] as $variant) {
                    $variantData = Arr::only($variant, [
                        'sku', 'sell_price', 'is_active'
                    ]);
                    
                    $variantId = (string) Str::orderedUuid();
                    DB::table('product_variants')->insert(array_merge($variantData, [
                        'id' => $variantId,
                        'product_id' => $productId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                    if (!empty($variant['options'])) {
                        $options = array_map(function ($opt) use ($variantId) {
                            return [
                                'variant_id' => $variantId,
                                'attribute_id' => $opt['attribute_id'],
                                'value' => $opt['value'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['options']);
                        DB::table('variant_options')->insert($options);
                    }

                    if (!empty($variant['media'])) {
                        $vMedia = array_map(function ($m) use ($productId, $variantId) {
                            return [
                                'product_id' => $productId,
                                'variant_id' => $variantId,
                                'media_type' => $m['media_type'] ?? 'image',
                                'url' => $m['url'],
                                'sort_order' => $m['sort_order'] ?? 0,
                                'is_primary' => $m['is_primary'] ?? false,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['media']);
                        DB::table('product_media')->insert($vMedia);
                    }

                    if (!empty($variant['wholesale_prices'])) {
                        $wholesales = array_map(function ($wp) use ($variantId) {
                            return [
                                'variant_id' => $variantId,
                                'min_qty' => $wp['min_qty'],
                                'price' => $wp['price'],
                                'customer_type' => $wp['customer_type'] ?? null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }, $variant['wholesale_prices']);
                        DB::table('product_wholesale_prices')->insert($wholesales);
                    }
                }
            }

            return $productId;
        });
    }
}
