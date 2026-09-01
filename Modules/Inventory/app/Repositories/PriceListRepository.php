<?php

namespace Modules\Inventory\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Models\ProductWholesalePrice;
use Modules\Product\Support\TechnicalSku;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PriceListRepository
{
    private const COLUMNS = ['id', 'product_id', 'sku', 'buy_price', 'sell_price'];

    public function paginate(?string $productId, int $perPage = 10): LengthAwarePaginator
    {
        $query = QueryBuilder::for(ProductVariant::query())
            ->select(self::COLUMNS)
            ->tap(fn ($q) => TechnicalSku::exclude($q, 'product_variants.sku'))
            ->with(['product:id,name', 'wholesalePrices'])
            ->allowedSearch('sku')
            ->allowedFilters(AllowedFilter::exact('product_id'))
            ->whereHas('product', fn ($q) => $q->whereNull('deleted_at'))
            ->defaultSort('sku')
            ->allowedSorts('sku', 'sell_price', 'buy_price');

        if ($productId) {
            $query->where('product_id', $productId);
        }

        return $query->paginate(request('per_page', $perPage))
            ->appends(request()->query());
    }

    public function findByIds(array $ids): Collection
    {
        return TechnicalSku::exclude(ProductVariant::select(self::COLUMNS))
            ->with(['product:id,name', 'wholesalePrices'])
            ->whereIn('id', $ids)
            ->get();
    }

    public function findVariant(string $id): ?ProductVariant
    {
        return ProductVariant::find($id);
    }

    public function saveVariant(ProductVariant $variant): bool
    {
        return $variant->save();
    }

    public function deleteWholesalePrices(string $variantId): void
    {
        ProductWholesalePrice::where('variant_id', $variantId)->delete();
    }

    public function createWholesalePrice(array $data): ProductWholesalePrice
    {
        return ProductWholesalePrice::create($data);
    }
}
