<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductVariant;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class PriceListRepository
{

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
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function updatePrices(array $items): void
    {
        $groups = [];

        foreach ($items as $item) {
            $payload = ['sell_price' => $item['sell_price']];
            if (array_key_exists('tax_rate', $item) && $item['tax_rate'] !== null) {
                $payload['tax_rate'] = $item['tax_rate'];
            }

            $key = json_encode($payload);
            $groups[$key]['payload'] = $payload;
            $groups[$key]['ids'][] = $item['variant_id'];
        }

        DB::transaction(function () use ($groups) {
            foreach ($groups as $group) {
                ProductVariant::whereIn('id', $group['ids'])->update($group['payload']);
            }
        });
    }
}
