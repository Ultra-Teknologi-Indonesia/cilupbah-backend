<?php

namespace Modules\Inventory\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductVariant;

class InventorySettingService
{
    public function products(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_stored', true)
            ->select('product_variants.*')
            ->selectRaw('products.name as product_name')
            ->selectRaw('products.purchase_lead_time as purchase_lead_time')
            ->withCount('unlimitedShops')
            ->with([
                'product:id,name,purchase_lead_time',
                'product.media' => fn ($q) => $q->whereNull('variant_id')->orderBy('sort_order'),
                'media' => fn ($q) => $q->orderBy('sort_order'),
                'options.attribute:id,name',
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($w) use ($like) {
                $w->where('product_variants.sku', 'ilike', $like)
                    ->orWhere('product_variants.barcode', 'ilike', $like)
                    ->orWhere('products.name', 'ilike', $like);
            });
        }

        return $query
            ->orderBy('products.name')
            ->orderBy('product_variants.sku')
            ->paginate($perPage)
            ->appends(request()->query());
    }

    public function updateThresholds(string $itemId, array $data): ProductVariant
    {
        $variant = ProductVariant::findOrFail($itemId);

        if (array_key_exists('min_stock', $data)) {
            $variant->min_stock = max(0, (int) $data['min_stock']);
        }
        if (array_key_exists('safe_stock', $data)) {
            $variant->safe_stock = max(0, (int) $data['safe_stock']);
        }

        $variant->save();

        return $variant->fresh();
    }
}
