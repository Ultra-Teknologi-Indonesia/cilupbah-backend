<?php

namespace Modules\Channel\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Product\Models\ProductSyncLog;
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
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function find(string $id): DownloadTransaction
    {
        return DownloadTransaction::query()
            ->with(self::RELATIONS)
            ->findOrFail($id);
    }

    public function failureLogs(DownloadTransaction $transaction): Collection
    {
        return ProductSyncLog::query()
            ->where('channel_shop_id', $transaction->channel_shop_id)
            ->where('action', ProductSyncLog::ACTION_DOWNLOAD)
            ->where('status', ProductSyncLog::STATUS_FAILED)
            ->where('created_at', '>=', $transaction->created_at)
            ->where('created_at', '<=', $transaction->updated_at)
            ->orderByDesc('created_at')
            ->get(['payload', 'error_message', 'created_at']);
    }

    public function paginateShopProducts(string $channelShopId): LengthAwarePaginator
    {
        return QueryBuilder::for(\Modules\Product\Models\Product::class)
            ->whereIn('status', [
                \Modules\Product\Models\Product::STATUS_MASTER,
                \Modules\Product\Models\Product::STATUS_DOWNLOAD,
            ])
            ->whereHas('channelMappings', fn ($query) => $query->where('channel_shop_id', $channelShopId))
            ->with([
                'media',
                'channelMappings' => fn ($query) => $query->where('channel_shop_id', $channelShopId),
            ])
            ->allowedFilters(
                AllowedFilter::callback('is_master', function ($query, $value) {
                    filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        ? $query->where('status', \Modules\Product\Models\Product::STATUS_MASTER)
                        : $query->where('status', '!=', \Modules\Product\Models\Product::STATUS_MASTER);
                }),
            )
            ->allowedSearch('name', 'sku')
            ->defaultSort('-updated_at')
            ->allowedSorts('name', 'updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }
}
