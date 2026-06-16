<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProductImportService
{
    public function processSingleProductRow(array $row)
    {
        DB::transaction(function () use ($row) {

            $categoryId = $this->resolveCategory($row['item_category_id'] ?? null, $row['category'] ?? '');

            $brandId = $this->resolveBrand($row['brand'] ?? '');

            $productName = $row['item_group_name'] ?? 'Unnamed Product';
            $product = DB::table('products')->where('name', $productName)->first();

            $productId = null;
            $productData = [
                'category_id' => $categoryId,
                'brand_id' => $brandId,
                'name' => $productName,
                'description' => $row['description'] ?? '',
                'weight' => $row['package_weight'] ?? 0,
                'length' => $row['package_length'] ?? 0,
                'width' => $row['package_width'] ?? 0,
                'height' => $row['package_height'] ?? 0,
                'updated_at' => now(),
            ];

            if ($product) {
                DB::table('products')->where('id', $product->id)->update($productData);
                $productId = $product->id;
            } else {
                $productData['id'] = \Ramsey\Uuid\Uuid::uuid7()->toString();
                $productData['created_at'] = now();
                DB::table('products')->insert($productData);
                $productId = $productData['id'];
            }

            $sku = $row['item_code'];
            $variant = DB::table('product_variants')->where('sku', $sku)->first();
            $variantData = [
                'product_id' => $productId,
                'sku' => $sku,
                'barcode' => $row['barcode'] ?? null,
                'sell_price' => $row['sell_price'] ?? 0,
                'updated_at' => now(),
            ];

            $variantId = null;
            if ($variant) {
                DB::table('product_variants')->where('id', $variant->id)->update($variantData);
                $variantId = $variant->id;
            } else {
                $variantData['id'] = \Ramsey\Uuid\Uuid::uuid7()->toString();
                $variantData['created_at'] = now();
                DB::table('product_variants')->insert($variantData);
                $variantId = $variantData['id'];
            }

            $this->processMedia($productId, $row);
        });
    }

    public function processBundleRow(array $row)
    {
        DB::transaction(function () use ($row) {
            $bundleSku = $row['item_code'];
            $componentSku = $row['sku_composition'];
            $qty = $row['qty'] ?? 1;

            $bundleVariant = DB::table('product_variants')->where('sku', $bundleSku)->first();
            $componentVariant = DB::table('product_variants')->where('sku', $componentSku)->first();

            if (!$bundleVariant) {
                throw new \Exception("Bundle SKU {$bundleSku} not found.");
            }
            if (!$componentVariant) {
                throw new \Exception("Component SKU {$componentSku} not found.");
            }

            $bundleProductId = $bundleVariant->product_id;

            $existing = DB::table('product_bundle_items')
                ->where('bundle_product_id', $bundleProductId)
                ->where('component_variant_id', $componentVariant->id)
                ->first();

            if ($existing) {
                DB::table('product_bundle_items')
                    ->where('id', $existing->id)
                    ->update([
                        'qty' => $qty,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('product_bundle_items')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'bundle_product_id' => $bundleProductId,
                    'component_variant_id' => $componentVariant->id,
                    'qty' => $qty,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    protected function resolveCategory($categoryId, $categoryName)
    {
        if ($categoryId && DB::table('categories')->where('id', $categoryId)->exists()) {
            return $categoryId;
        }

        if (empty($categoryName)) {
            $categoryName = 'Uncategorized';
        }

        $cat = DB::table('categories')->where('name', $categoryName)->first();
        if ($cat) {
            return $cat->id;
        }

        return DB::table('categories')->insertGetId([
            'name' => $categoryName,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function resolveBrand($brandName)
    {
        if (empty($brandName)) {
            return null;
        }

        $brand = DB::table('brands')->where('name', $brandName)->first();
        if ($brand) {
            return $brand->id;
        }

        return DB::table('brands')->insertGetId([
            'name' => $brandName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function processMedia(int $productId, array $row)
    {
        $imageUrls = [
            $row['image_url1'] ?? null,
            $row['image_url2'] ?? null,
            $row['image_url3'] ?? null,
            $row['image_url4'] ?? null,
            $row['image_url5'] ?? null,
            $row['default_images'] ?? null,
        ];

        $imageUrls = array_filter($imageUrls); 

        $hasMedia = DB::table('product_media')->where('product_id', $productId)->exists();

        if (!$hasMedia && count($imageUrls) > 0) {
            $insertData = [];
            foreach ($imageUrls as $index => $url) {
                $insertData[] = [
                    'product_id' => $productId,
                    'media_type' => 'image',
                    'url' => $url,
                    'is_primary' => $index === 0,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('product_media')->insert($insertData);
        }
    }
}
