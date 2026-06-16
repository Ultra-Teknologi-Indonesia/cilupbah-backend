<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Modules\Product\Exceptions\BundleCompositionException;
use Modules\Product\Exceptions\ProductHasTransactionsException;
use Modules\Product\Models\Product;

/**
 * B2 business guards for bundle composition. Centralised so every write path
 * (ProductService + Inventory\BundleController) enforces the same invariants.
 */
class BundleGuardService
{
    /** Channel mappings considered "active" (the product is live on a channel). */
    private const ACTIVE_SYNC_STATUSES = ['synced', 'syncing'];

    /**
     * Bundle-in-bundle guard: no component may be a variant of a bundle product.
     *
     * @param array<int, string|null> $componentVariantIds
     */
    public function assertComponentsNotBundles(array $componentVariantIds): void
    {
        $ids = array_values(array_filter($componentVariantIds));

        if (empty($ids)) {
            return;
        }

        $offending = DB::table('product_variants as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $ids)
            ->where('p.is_bundle', true)
            ->pluck('v.sku')
            ->all();

        if (! empty($offending)) {
            throw new BundleCompositionException(
                'Komponen bundle tidak boleh berupa varian dari produk bundle (bundle-in-bundle): '
                . implode(', ', $offending)
            );
        }
    }

    /**
     * Transaction-lock guard: an existing non-bundle product that already has
     * transactions cannot be converted into a bundle. New products (null id) and
     * products that are already bundles (editing composition) are allowed.
     */
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
            && DB::table('inventory_movements')->whereIn('item_id', $variantIds)->exists();

        $hasOrderLines = ! empty($variantIds)
            && DB::table('sales_order_items')->whereIn('item_id', $variantIds)->exists();

        $hasActiveListing = DB::table('product_channel_mappings')
            ->where('product_id', $productId)
            ->whereIn('sync_status', self::ACTIVE_SYNC_STATUSES)
            ->exists();

        if ($hasLedger || $hasOrderLines || $hasActiveListing) {
            throw new ProductHasTransactionsException(
                'Produk sudah memiliki transaksi (mutasi stok / baris pesanan / listing channel aktif) '
                . 'sehingga tidak bisa diubah menjadi bundle.'
            );
        }
    }
}
