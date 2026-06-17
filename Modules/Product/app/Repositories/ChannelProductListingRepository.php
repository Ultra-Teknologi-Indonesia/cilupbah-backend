<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductChannelMapping;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ChannelProductListingRepository
{
    private const RELATIONS = [
        'product.media',
        'channelShop.channel',
        'variantMappings.variant.options.attribute',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductChannelMapping::class)
            ->leftJoin('products', 'products.id', '=', 'product_channel_mappings.product_id')
            ->select('product_channel_mappings.*')
            ->with(self::RELATIONS)
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters(
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where('shop_id', $value))),
                AllowedFilter::callback('sync_status', fn ($query, $value) => $query->where('product_channel_mappings.sync_status', $value)),
                AllowedFilter::callback('min_price', fn ($query, $value) => $this->filterByPrice($query, '>=', $value)),
                AllowedFilter::callback('max_price', fn ($query, $value) => $this->filterByPrice($query, '<=', $value)),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'product_channel_mappings.created_at'),
                AllowedSort::field('last_synced_at', 'product_channel_mappings.last_synced_at'),
            )
            ->defaultSort('-product_channel_mappings.created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    /**
     * Filter mapping yang punya minimal satu varian dengan harga efektif
     * (override_price bila ada, jika tidak sell_price master) memenuhi batas.
     */
    private function filterByPrice($query, string $operator, $value)
    {
        return $query->whereHas('variantMappings', function ($mapping) use ($operator, $value) {
            $mapping->join('product_variants', 'product_variants.id', '=', 'product_variant_channel_mappings.variant_id')
                ->whereRaw(
                    "COALESCE(product_variant_channel_mappings.override_price, product_variants.sell_price) {$operator} ?",
                    [(float) $value]
                );
        });
    }

    public function find(string $id): ProductChannelMapping
    {
        return ProductChannelMapping::query()
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }
}
