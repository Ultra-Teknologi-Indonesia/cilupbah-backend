<?php

namespace Modules\Product\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Modules\Product\Models\ProductSyncLog;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class UploadHistoryRepository
{
    private const RELATIONS = [
        'product:id,name',
        'product.variants:id,product_id,sku',
        'product.media',
        'product.channelMappings:id,product_id,channel_shop_id,channel_url,external_product_id',
        'channelShop.channel',
    ];

    public function paginate(int $retentionDays = 30): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductSyncLog::class)
            ->select('product_sync_logs.*')
            ->leftJoin('products', 'products.id', '=', 'product_sync_logs.product_id')
            ->where('product_sync_logs.action', ProductSyncLog::ACTION_UPLOAD)
            ->where(function ($query) use ($retentionDays) {
                $query->where('product_sync_logs.status', '!=', ProductSyncLog::STATUS_SUCCESS)
                    ->orWhere('product_sync_logs.created_at', '>=', Carbon::now()->subDays($retentionDays));
            })
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

    public function findUpload(string $id): ProductSyncLog
    {
        return ProductSyncLog::query()
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->findOrFail($id);
    }

    public function deleteMany(array $ids): int
    {
        return ProductSyncLog::query()
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->whereIn('id', $ids)
            ->delete();
    }

    public function pruneSuccessOlderThan(int $days): int
    {
        return ProductSyncLog::query()
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->where('status', ProductSyncLog::STATUS_SUCCESS)
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }
}
