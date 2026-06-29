<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

class ChannelMonitorRepository
{

    public function getMonitoredProducts(?string $channelShopId, ?string $syncStatus): LengthAwarePaginator
    {
        return QueryBuilder::for(Product::class)
            ->with([
                'media',
                'channelMappings' => function ($q) use ($channelShopId, $syncStatus) {
                    if ($channelShopId) {
                        $q->where('channel_shop_id', $channelShopId);
                    }
                    if ($syncStatus) {
                        $q->where('sync_status', $syncStatus);
                    }
                    $q->with([
                        'channelShop.channel',
                        'variantMappings.variant:id,product_id,sku,sell_price',
                    ]);
                },
            ])
            ->whereHas('channelMappings', function ($q) use ($channelShopId, $syncStatus) {
                if ($channelShopId) {
                    $q->where('channel_shop_id', $channelShopId);
                }
                if ($syncStatus) {
                    $q->where('sync_status', $syncStatus);
                }
            })
            ->allowedSearch('name')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('is_active')
            )
            ->allowedSorts('name', 'created_at', 'updated_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function getShopsSummary(?string $channelCode): \Illuminate\Support\Collection
    {
        return ChannelShop::with('channel')
            ->where('is_active', true)
            ->when($channelCode, fn ($q) => $q->whereHas('channel', fn ($q2) => $q2->where('code', $channelCode)))
            ->get();
    }

    public function getShopStats(string $channelShopId): array
    {
        return ProductChannelMapping::where('channel_shop_id', $channelShopId)
            ->get(['sync_status'])
            ->countBy('sync_status')
            ->toArray();
    }

    public function getLastSyncedAt(string $channelShopId): ?string
    {
        return ProductChannelMapping::where('channel_shop_id', $channelShopId)
            ->whereNotNull('last_synced_at')
            ->max('last_synced_at');
    }

    public function getFailedLast24h(string $channelShopId): int
    {
        return ProductChannelMapping::where('channel_shop_id', $channelShopId)
            ->where('sync_status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->count();
    }

    public function getRecentFailures(string $channelShopId): \Illuminate\Support\Collection
    {
        return ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $channelShopId)
            ->where('sync_status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();
    }

    public function getPendingProducts(string $channelShopId): \Illuminate\Support\Collection
    {
        return ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $channelShopId)
            ->where('sync_status', 'pending')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    public function getShopProducts(string $channelShopId, ?string $syncStatus): LengthAwarePaginator
    {
        return QueryBuilder::for(ProductChannelMapping::class)
            ->select('product_channel_mappings.*')
            ->leftJoin('products', 'products.id', '=', 'product_channel_mappings.product_id')
            ->where('product_channel_mappings.channel_shop_id', $channelShopId)
            ->when($syncStatus, fn ($query) => $query->where('product_channel_mappings.sync_status', $syncStatus))
            ->with([
                'product:id,name,sku,status',
                'product.media',
                'variantMappings.variant:id,product_id,sku,sell_price',
            ])
            ->allowedSearch('products.name', 'products.sku')
            ->allowedSorts(
                AllowedSort::field('last_synced_at', 'product_channel_mappings.last_synced_at'),
                AllowedSort::field('created_at', 'product_channel_mappings.created_at'),
            )
            ->defaultSort('-product_channel_mappings.last_synced_at')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function findShopByShopId(string $shopId): ?ChannelShop
    {
        return ChannelShop::with('channel')->where('shop_id', $shopId)->first();
    }

    public function resolveChannelShopId(string $shopId): ?string
    {
        return ChannelShop::where('shop_id', $shopId)->value('id');
    }
}
