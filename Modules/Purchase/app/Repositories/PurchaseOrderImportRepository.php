<?php

namespace Modules\Purchase\Repositories;

use Illuminate\Support\Collection;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Contact;
use Modules\Tax\Models\Tax;
use Modules\Warehouse\Models\Location;

class PurchaseOrderImportRepository
{
    public function getActiveSuppliers(): Collection
    {
        return Contact::whereIn('type', [Contact::TYPE_SUPPLIER, Contact::TYPE_BOTH])
            ->where('status', Contact::STATUS_ACTIVE)
            ->get(['id', 'name', 'phone', 'email', 'code']);
    }

    public function getActiveWarehouses(): Collection
    {
        return Location::where('is_warehouse', true)
            ->where('is_active', true)
            ->get(['id', 'location_name', 'location_code', 'location_type']);
    }

    public function getActiveTaxes(): Collection
    {
        return Tax::where('is_active', true)
            ->get(['id', 'name', 'rate', 'is_active']);
    }

    public function getVariantsBySkus(array $skus): Collection
    {
        if (empty($skus)) {
            return collect();
        }

        return ProductVariant::with('product:id,name')
            ->whereIn('sku', array_unique($skus))
            ->get(['id', 'product_id', 'sku', 'barcode', 'buy_price']);
    }

    public function getMasterSkuList(int $limit = 3000): Collection
    {
        return ProductVariant::with('product:id,name')
            ->where('is_active', true)
            ->orderBy('sku')
            ->take($limit)
            ->get(['sku', 'product_id', 'barcode', 'buy_price']);
    }

    public function getExistingPoNumbers(array $poNumbers): array
    {
        if (empty($poNumbers)) {
            return [];
        }

        return PurchaseOrder::whereIn('po_number', $poNumbers)
            ->pluck('po_number')
            ->all();
    }
}
