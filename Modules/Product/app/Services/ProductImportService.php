<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ProductImportRepository;

class ProductImportService
{
    public function __construct(private ProductImportRepository $repository) {}

    public function processSingleProductRow(array $row)
    {
        DB::transaction(function () use ($row) {

            $categoryId = $this->resolveCategory($row['item_category_id'] ?? null, $row['category'] ?? '');

            $productName = $row['item_group_name'] ?? 'Unnamed Product';

            $weight = $row['package_weight'] ?? 0;
            $length = $row['package_length'] ?? 0;
            $width = $row['package_width'] ?? 0;
            $height = $row['package_height'] ?? 0;

            $productId = $this->repository->upsertProductByName($productName, [
                'category_id' => $categoryId,
                'name' => $productName,
                'description' => $row['description'] ?? '',
                'weight' => $weight,
                'length' => $length,
                'width' => $width,
                'height' => $height,

                'status' => Product::STATUS_MASTER,
                'is_active' => true,
            ]);

            $sku = $row['item_code'];

            $this->repository->upsertVariantBySku($sku, [
                'product_id' => $productId,
                'sku' => $sku,
                'barcode' => $row['barcode'] ?? null,
                'sell_price' => $row['sell_price'] ?? 0,
                'weight' => $weight,
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'is_active' => true,
            ]);

            $this->processMedia($productId, $row);
        });
    }

    public function processBundleRow(array $row)
    {
        DB::transaction(function () use ($row) {
            $bundleSku = $row['item_code'];
            $componentSku = $row['sku_composition'];
            $qty = (int) ($row['qty'] ?? 1);

            if ($bundleSku === $componentSku) {
                throw new \Exception("SKU komponen ({$componentSku}) tidak boleh sama dengan SKU bundle.");
            }

            if ($qty < 1) {
                throw new \Exception("Jumlah komponen minimal 1.");
            }

            $bundleVariant = $this->repository->findVariantBySku($bundleSku);
            $componentVariant = $this->repository->findVariantBySku($componentSku);

            if (!$bundleVariant) {
                throw new \Exception("SKU bundle {$bundleSku} tidak ditemukan.");
            }
            if (!$componentVariant) {
                throw new \Exception("SKU komponen {$componentSku} tidak ditemukan.");
            }

            if ($componentVariant->product?->is_bundle) {
                throw new \Exception("Komponen bundle tidak boleh berupa produk bundle (bundle-in-bundle tidak diizinkan).");
            }

            if (!$componentVariant->is_active) {
                throw new \Exception("Varian komponen {$componentSku} tidak aktif.");
            }

            Product::where('id', $bundleVariant->product_id)->update(['is_bundle' => true]);

            $this->repository->upsertBundleItem($bundleVariant->product_id, $componentVariant->id, $qty);
        });
    }

    protected function resolveCategory($categoryId, $categoryName)
    {
        if ($categoryId && $this->repository->categoryExists($categoryId)) {
            return $categoryId;
        }

        if (empty($categoryName)) {
            $categoryName = 'Uncategorized';
        }

        return $this->repository->findOrCreateCategoryByName($categoryName);
    }

    protected function processMedia(string $productId, array $row)
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

        if ($this->repository->hasMedia($productId) || count($imageUrls) === 0) {
            return;
        }

        $insertData = [];
        foreach ($imageUrls as $index => $url) {
            $insertData[] = [
                'product_id' => $productId,
                'media_type' => 'image',
                'url' => $url,
                'is_primary' => $index === 0,
                'sort_order' => $index + 1,
            ];
        }

        $this->repository->insertMedia($insertData);
    }
}
