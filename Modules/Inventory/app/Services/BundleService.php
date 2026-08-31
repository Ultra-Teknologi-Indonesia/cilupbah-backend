<?php

namespace Modules\Inventory\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Repositories\BundleRepository;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\ProductRepository;

class BundleService
{
    public function __construct(
        private BundleRepository $bundleRepository,
        private ProductRepository $productRepository,
    ) {
    }

    public function list(int $perPage = 10): LengthAwarePaginator
    {
        return $this->bundleRepository->paginate($perPage);
    }

    public function save(array $payload): array
    {
        return DB::transaction(fn () => $this->saveInTransaction($payload));
    }

    private function saveInTransaction(array $payload): array
    {
        $componentVariantIds = array_values(array_filter(array_column($payload['components'], 'variant_id')));
        if ($this->productRepository->variantIdsFromBundleProducts($componentVariantIds) !== []) {
            throw new \RuntimeException('Komponen bundle tidak boleh berisi produk bundle (bundle-in-bundle tidak diizinkan).');
        }

        $id = $payload['id'] ?? null;

        if ($id) {
            $product = $this->bundleRepository->findBundleOrFail($id);
            $product->update([
                'name' => $payload['name'],
                'sku'  => $payload['sku'],
            ]);
            $this->productRepository->ensureActiveBundleVariant(
                $product,
                array_key_exists('sell_price', $payload) ? $payload['sell_price'] : null,
            );
        } else {
            $categoryId = $payload['category_id'] ?? $this->bundleRepository->firstCategoryId();

            $product = $this->bundleRepository->create([
                'name'        => $payload['name'],
                'sku'         => $payload['sku'],
                'category_id' => $categoryId,
                'is_bundle'   => true,
                'status'      => Product::STATUS_MASTER,
                'is_active'   => true,
            ]);
            $product->variants()->create([
                'sku'        => $payload['sku'],
                'sell_price' => $payload['sell_price'] ?? 0,
                'is_active'  => true,
            ]);
        }

        $product->bundleItems()->delete();
        foreach ($payload['components'] as $comp) {
            $product->bundleItems()->create([
                'component_variant_id' => $comp['variant_id'],
                'qty'                  => $comp['qty'],
            ]);
        }

        return [$this->bundleRepository->loadDetail($product), (bool) $id];
    }
}
