<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ProductUploadListingRepository
{
    /**
     * Toko tujuan untuk satu produk, dipecah berdasarkan apakah produk sudah
     * diupload ke toko itu (ada ProductChannelMapping) atau belum.
     */
    public function paginateDestinations(string $productId, bool $isUploaded): LengthAwarePaginator
    {
        return QueryBuilder::for(ChannelShop::class)
            ->where('channel_shops.is_active', true)
            ->whereNull('channel_shops.disconnected_at')
            ->with('channel')
            ->allowedSearch('channel_shops.shop_name')
            ->allowedFilters(
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channel', fn ($channel) => $channel->where('code', $value))),
            )
            ->when(
                $isUploaded,
                fn ($query) => $query->whereHas('productMappings', fn ($mapping) => $mapping->where('product_id', $productId)),
                fn ($query) => $query->whereDoesntHave('productMappings', fn ($mapping) => $mapping->where('product_id', $productId)),
            )
            ->allowedSorts(
                AllowedSort::field('shop_name', 'channel_shops.shop_name'),
                AllowedSort::field('created_at', 'channel_shops.created_at'),
            )
            ->defaultSort('channel_shops.shop_name')
            ->paginate(request('per_page', 25))
            ->appends(request()->query());
    }
}
