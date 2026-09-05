<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductImportRepository;
use Modules\Product\Repositories\ProductRepository;

class ProductImportService
{
    public function __construct(
        private ProductImportRepository $repository,
        private ProductRepository $productRepository,
    ) {}

    public function processSingleProductRow(array $row)
    {
        DB::transaction(function () use ($row) {

            $categoryId = $this->resolveCategory($row['item_category_id'] ?? null, $row['category'] ?? '');

            $productName = $row['item_group_name'] ?? 'Unnamed Product';
            $sku = $row['item_code'];

            $weight = $row['package_weight'] ?? 0;
            $length = $row['package_length'] ?? 0;
            $width = $row['package_width'] ?? 0;
            $height = $row['package_height'] ?? 0;

            $productId = $this->repository->upsertProductByName($productName, [
                'category_id' => $categoryId,
                'sku' => $sku,
                'name' => $productName,
                'description' => $row['description'] ?? '',
                'weight' => $weight,
                'length' => $length,
                'width' => $width,
                'height' => $height,

                'status' => Product::STATUS_MASTER,
                'is_active' => true,
            ]);

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
            $bundleSku = trim((string) ($row['item_code'] ?? ''));
            $componentSku = trim((string) ($row['sku_composition'] ?? ''));
            $qty = (int) ($row['qty'] ?? 1);

            $this->assertBundleRowSemantics($row);

            if ($bundleSku === $componentSku) {
                throw new \Exception("SKU komponen ({$componentSku}) tidak boleh sama dengan SKU bundle.");
            }

            if ($qty < 1) {
                throw new \Exception("Jumlah komponen minimal 1.");
            }

            $bundleProduct = $this->repository->findActiveBundleBySku($bundleSku);
            $componentVariant = $this->repository->findVariantBySku($componentSku);

            if (! $bundleProduct) {
                $existingProduct = $this->repository->findActiveProductBySku($bundleSku);
                if ($existingProduct) {
                    throw new \Exception(
                        "SKU bundle {$bundleSku} sudah dipakai produk non-bundle {$existingProduct->name}. "
                        .'Gunakan SKU bundle yang benar.'
                    );
                }

                $bundleName = $row['bundle_name'] ?? $row['item_group_name'] ?? $row['name'] ?? null;
                if (! $bundleName) {
                    throw new \Exception("SKU bundle {$bundleSku} belum ada di master produk. Isi kolom 'bundle_name' untuk membuat produk bundle baru secara otomatis.");
                }

                $categoryId = $this->resolveCategory($row['item_category_id'] ?? null, $row['category'] ?? '');
                $bundleName = trim((string) $bundleName);
                $productId = $this->repository->upsertProductByName($bundleName, [
                    'category_id' => $categoryId,
                    'sku' => $bundleSku,
                    'name' => $bundleName,
                    'description' => $row['description'] ?? '',
                    'is_bundle' => true,
                    'status' => Product::STATUS_MASTER,
                    'is_active' => true,
                ]);

                $bundleProduct = Product::query()->findOrFail($productId);
            }

            if (! $componentVariant) {
                throw new \Exception("SKU komponen {$componentSku} tidak ditemukan.");
            }

            if ($componentVariant->product?->is_bundle) {
                throw new \Exception("Komponen bundle tidak boleh berupa produk bundle (bundle-in-bundle tidak diizinkan).");
            }

            if (!$componentVariant->is_active) {
                throw new \Exception("Varian komponen {$componentSku} tidak aktif.");
            }

            $bundleProductId = (string) $bundleProduct->id;

            $bundleProduct->update([
                'is_bundle' => true,
                'sku' => $bundleSku,
            ]);

            $this->productRepository->ensureActiveBundleVariant(
                $bundleProduct->refresh(),
                $row['sell_price'] ?? null,
            );

            $this->repository->upsertBundleItem($bundleProductId, $componentVariant->id, $qty);
        });
    }

    public function validateSingleProductRow(array $row): void
    {
        if (! empty($row['item_category_id']) && ! $this->repository->categoryExists($row['item_category_id'])) {
            throw new \Exception("ID Kategori {$row['item_category_id']} tidak ditemukan.");
        }
    }

    public function validateBundleRow(array $row): void
    {
        $bundleSku = trim((string) ($row['item_code'] ?? ''));
        $componentSku = trim((string) ($row['sku_composition'] ?? ''));
        $qty = (int) ($row['qty'] ?? 1);

        if (! $bundleSku) {
            throw new \Exception("Kolom 'item_code' (SKU Bundle) wajib diisi.");
        }

        if (! $componentSku) {
            throw new \Exception("Kolom 'sku_composition' (SKU Komponen) wajib diisi.");
        }

        if ($bundleSku === $componentSku) {
            throw new \Exception("SKU komponen ({$componentSku}) tidak boleh sama dengan SKU bundle.");
        }

        if ($qty < 1) {
            throw new \Exception("Jumlah komponen minimal 1.");
        }

        $this->assertBundleRowSemantics($row);

        $bundleProduct = $this->repository->findActiveBundleBySku($bundleSku);
        $componentVariant = $this->repository->findVariantBySku($componentSku);

        if (! $bundleProduct) {
            $existingProduct = $this->repository->findActiveProductBySku($bundleSku);
            if ($existingProduct) {
                throw new \Exception(
                    "SKU bundle {$bundleSku} sudah dipakai produk non-bundle {$existingProduct->name}. "
                    .'Gunakan SKU bundle yang benar.'
                );
            }

            $bundleName = $row['bundle_name'] ?? $row['item_group_name'] ?? $row['name'] ?? null;
            if (! $bundleName) {
                throw new \Exception("SKU bundle {$bundleSku} belum ada di master produk. Isi kolom 'bundle_name' untuk membuat produk bundle baru secara otomatis.");
            }
        }

        if (! $componentVariant) {
            throw new \Exception("SKU komponen {$componentSku} tidak ditemukan.");
        }

        if ($componentVariant->product?->is_bundle) {
            throw new \Exception("Komponen bundle tidak boleh berupa produk bundle (bundle-in-bundle tidak diizinkan).");
        }

        if (! $componentVariant->is_active) {
            throw new \Exception("Varian komponen {$componentSku} tidak aktif.");
        }
    }

    private function assertBundleRowSemantics(array $row): void
    {
        $bundleSku = trim((string) ($row['item_code'] ?? ''));
        $bundleName = trim((string) ($row['bundle_name'] ?? $row['item_group_name'] ?? $row['name'] ?? ''));

        if ($bundleSku === '' || $bundleName === '') {
            return;
        }

        $nameIsKnownSku = Product::withTrashed()
            ->whereRaw('LOWER(sku) = LOWER(?)', [$bundleName])
            ->exists()
            || ProductVariant::withTrashed()
                ->whereRaw('LOWER(sku) = LOWER(?)', [$bundleName])
                ->exists();

        $skuIsKnownName = Product::withTrashed()
            ->whereRaw('LOWER(name) = LOWER(?)', [$bundleSku])
            ->exists();

        $skuLooksLikeName = preg_match('/[\s+]/u', $bundleSku) === 1;
        $nameLooksLikeSku = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $bundleName) === 1;

        if (($nameIsKnownSku && $skuIsKnownName) || ($skuLooksLikeName && $nameLooksLikeSku)) {
            throw new \Exception(
                'Kolom import bundle tertukar: item_code harus berisi SKU bundle, sedangkan bundle_name harus berisi nama bundle.'
            );
        }
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
