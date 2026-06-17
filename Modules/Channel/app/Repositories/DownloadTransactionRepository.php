<?php

namespace Modules\Channel\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\DownloadTransaction;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class DownloadTransactionRepository
{
    private const RELATIONS = [
        'channelShop.channel',
        'executor:id,email',
    ];

    public function paginate(): LengthAwarePaginator
    {
        return QueryBuilder::for(DownloadTransaction::class)
            ->with(self::RELATIONS)
            ->allowedFilters(
                AllowedFilter::exact('state'),
                AllowedFilter::callback('channel', fn ($query, $value) => $query->whereHas('channelShop.channel', fn ($channel) => $channel->where('code', $value))),
                AllowedFilter::callback('shop_id', fn ($query, $value) => $query->whereHas('channelShop', fn ($shop) => $shop->where('shop_id', $value))),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->whereDate('created_at', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->whereDate('created_at', '<=', $value)),
            )
            ->allowedSorts('created_at', 'trx_no')
            ->defaultSort('-created_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function find(string $id): DownloadTransaction
    {
        return DownloadTransaction::query()
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    public function paginateShopProducts(string $channelShopId): LengthAwarePaginator
    {
        return QueryBuilder::for(\Modules\Product\Models\Product::class)
            ->whereIn('status', [
                \Modules\Product\Models\Product::STATUS_DOWNLOAD,
            ])
            ->whereHas('channelMappings', fn ($query) => $query->where('channel_shop_id', $channelShopId))
            ->with([
                'variants:id,product_id,sku',
                'media',
                'channelMappings' => fn ($query) => $query->where('channel_shop_id', $channelShopId),
                'merge',
            ])
            ->allowedFilters(
                AllowedFilter::callback('is_master', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->whereHas('merge')
                        : $query->whereDoesntHave('merge');
                }),
            )
            ->allowedSearch('name', 'sku')
            ->defaultSort('-updated_at')
            ->allowedSorts('name', 'updated_at')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }
}
