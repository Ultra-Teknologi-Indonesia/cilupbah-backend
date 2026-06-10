<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PriceListRepository
{
    /** Listing harga produk (per varian) — Spatie Query Builder. */
    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductVariant::class)
            ->with(['product:id,name,sku', 'wholesalePrices'])
            ->allowedFilters(
                AllowedFilter::partial('sku'),
                AllowedFilter::exact('product_id'),
            )
            ->allowedSorts('sku', 'sell_price')
            ->defaultSort('sku')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    /**
     * Update massal harga jual (& pajak) per varian dalam satu transaksi.
     *
     * @param  array<int,array{variant_id:string,sell_price:float|int,tax_rate?:float|int|null}>  $items
     */
    public function updatePrices(array $items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $payload = ['sell_price' => $item['sell_price']];
                if (array_key_exists('tax_rate', $item) && $item['tax_rate'] !== null) {
                    $payload['tax_rate'] = $item['tax_rate'];
                }

                ProductVariant::where('id', $item['variant_id'])->update($payload);
            }
        });
    }
}
