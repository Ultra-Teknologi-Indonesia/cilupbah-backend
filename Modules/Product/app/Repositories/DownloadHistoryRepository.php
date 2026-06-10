<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Product\Models\ProductSyncLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class DownloadHistoryRepository
{
    private const RELATIONS = [
        'product:id,name,sku',
        'channelShop.channel',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductSyncLog::class)
            ->select('product_sync_logs.*')
            ->leftJoin('products', 'products.id', '=', 'product_sync_logs.product_id')
            ->where('product_sync_logs.action', ProductSyncLog::ACTION_DOWNLOAD)
            ->allowedSearch('products.name', 'products.sku')
            ->allowedFilters(
                AllowedFilter::callback('status', fn ($query, $value) => $query->where('product_sync_logs.status', $value)),
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where('shop_id', $value))),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->whereDate('product_sync_logs.created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->whereDate('product_sync_logs.created_at', '<=', $value)),
            )
            ->allowedSorts(
                AllowedSort::field('created_at', 'product_sync_logs.created_at'),
            )
            ->defaultSort('-created_at')
            ->with(self::RELATIONS)
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
