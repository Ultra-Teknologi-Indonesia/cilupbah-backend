<?php

namespace Modules\Product\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ChannelMonitorRepository
{
    /**
     * Produk yang terhubung ke setidaknya satu channel, beserta data sync-nya.
     * Mendukung filter: shop_id, channel (code), sync_status, search.
     */
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
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    /**
     * Ringkasan statistik per toko aktif.
     */
    public function getShopsSummary(?string $channelCode): \Illuminate\Support\Collection
    {
        return ChannelShop::with('channel')
            ->where('is_active', true)
            ->when($channelCode, fn ($q) => $q->whereHas('channel', fn ($q2) => $q2->where('code', $channelCode)))
            ->get();
    }

    /**
     * Detail satu toko: stats grouped by sync_status.
     */
    public function getShopStats(string $channelShopId): array
    {
        return ProductChannelMapping::where('channel_shop_id', $channelShopId)
            ->selectRaw('sync_status, count(*) as count')
            ->groupBy('sync_status')
            ->pluck('count', 'sync_status')
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

    /**
     * Produk yang gagal sync (20 terbaru) untuk satu toko.
     */
    public function getRecentFailures(string $channelShopId): \Illuminate\Support\Collection
    {
        return ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $channelShopId)
            ->where('sync_status', 'failed')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();
    }

    /**
     * Produk pending (20 terbaru) untuk satu toko.
     */
    public function getPendingProducts(string $channelShopId): \Illuminate\Support\Collection
    {
        return ProductChannelMapping::with('product:id,name,sku')
            ->where('channel_shop_id', $channelShopId)
            ->where('sync_status', 'pending')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * Produk di satu toko (paginated) dengan filter sync_status & search.
     */
    public function getShopProducts(string $channelShopId, ?string $syncStatus, ?string $search): LengthAwarePaginator
    {
        $query = ProductChannelMapping::with([
                'product:id,name,sku,status',
                'product.media',
                'variantMappings.variant:id,product_id,sku,sell_price',
            ])
            ->where('channel_shop_id', $channelShopId);

        if ($syncStatus) {
            $query->where('sync_status', $syncStatus);
        }

        if ($search) {
            $query->whereHas('product', fn ($q) =>
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%")
            );
        }

        return $query
            ->orderByDesc('last_synced_at')
            ->paginate(request('per_page', 10))
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
