<?php

namespace Modules\Product\Services;

use Modules\Product\Exceptions\BundleCompositionException;
use Modules\Product\Exceptions\ProductHasTransactionsException;
use Modules\Product\Models\Product;
use Modules\Product\Repositories\BundleGuardRepository;

class BundleGuardService
{

    private const ACTIVE_SYNC_STATUSES = ['synced', 'syncing'];

    public function __construct(private BundleGuardRepository $repository) {}

    public function assertComponentsNotBundles(array $componentVariantIds): void
    {
        $ids = array_values(array_filter($componentVariantIds));

        if (empty($ids)) {
            return;
        }

        $offending = $this->repository->bundleComponentSkus($ids);

        if (! empty($offending)) {
            throw new BundleCompositionException(
                'Komponen bundle tidak boleh berupa varian dari produk bundle (bundle-in-bundle): '
                . implode(', ', $offending)
            );
        }
    }

    public function assertConvertibleToBundle(?string $productId): void
    {
        if ($productId === null) {
            return;
        }

        $product = Product::find($productId);

        if (! $product || $product->is_bundle) {
            return;
        }

        $variantIds = $product->variants()->pluck('id')->all();

        $hasLedger = ! empty($variantIds)
            && $this->repository->hasInventoryMovements($variantIds);

        $hasOrderLines = ! empty($variantIds)
            && $this->repository->hasSalesOrderLines($variantIds);

        $hasActiveListing = $this->repository->hasActiveChannelListing($productId, self::ACTIVE_SYNC_STATUSES);

        if ($hasLedger || $hasOrderLines || $hasActiveListing) {
            throw new ProductHasTransactionsException(
                'Produk sudah memiliki transaksi (mutasi stok / baris pesanan / listing channel aktif) '
                . 'sehingga tidak bisa diubah menjadi bundle.'
            );
        }
    }
}
