<?php

namespace Modules\Product\Repositories;

use Modules\Product\Models\Brand;
use Modules\Product\Models\Category;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductMedia;
use Modules\Product\Models\ProductVariant;

class ProductImportRepository
{

    public function upsertProductByName(string $name, array $data): string
    {
        return (string) Product::updateOrCreate(['name' => $name], $data)->id;
    }

    public function upsertVariantBySku(string $sku, array $data): string
    {
        return (string) ProductVariant::updateOrCreate(['sku' => $sku], $data)->id;
    }

    public function findVariantBySku(string $sku): ?ProductVariant
    {
        return ProductVariant::where('sku', $sku)->first();
    }

    public function upsertBundleItem(string $bundleProductId, string $componentVariantId, $qty): void
    {
        ProductBundleItem::updateOrCreate(
            ['bundle_product_id' => $bundleProductId, 'component_variant_id' => $componentVariantId],
            ['qty' => $qty]
        );
    }

    public function categoryExists($categoryId): bool
    {
        return Category::whereKey($categoryId)->exists();
    }

    public function findOrCreateCategoryByName(string $name): int
    {
        return (int) Category::firstOrCreate(['name' => $name], ['is_active' => true])->id;
    }

    public function findOrCreateBrandByName(string $name): int
    {
        return (int) Brand::firstOrCreate(['name' => $name])->id;
    }

    public function hasMedia($productId): bool
    {
        return ProductMedia::where('product_id', $productId)->exists();
    }

    public function insertMedia(array $rows): void
    {
        foreach ($rows as $row) {
            ProductMedia::create($row);
        }
    }
}
