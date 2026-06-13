<?php

namespace Modules\Product\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Product\Repositories\ChannelMonitorRepository;

class ChannelMonitorService
{
    public function __construct(
        protected ChannelMonitorRepository $repo,
    ) {}

    public function getMonitoredProducts(
        ?string $shopId,
        ?string $channelCode,
        ?string $syncStatus
    ): LengthAwarePaginator {
        $channelShopId = null;

        if ($shopId) {
            $channelShopId = $this->repo->resolveChannelShopId($shopId);

            if (!$channelShopId) {
                return new LengthAwarePaginator([], 0, 10);
            }
        } elseif ($channelCode) {

        }

        return $this->repo->getMonitoredProducts($channelShopId, $syncStatus);
    }

    public function getShopsSummary(?string $channelCode): Collection
    {
        $shops = $this->repo->getShopsSummary($channelCode);

        return $shops->map(fn ($shop) => $this->buildShopSummary($shop));
    }

    public function getShopDetail(string $shopId): array
    {
        $shop = $this->repo->findShopByShopId($shopId);
        if (!$shop) {
            return [];
        }

        $summary = $this->buildShopSummary($shop);

        $recentFailures = $this->repo->getRecentFailures($shop->id)
            ->map(fn ($m) => [
                'product_id'          => $m->product_id,
                'product_name'        => $m->product->name ?? null,
                'sku'                 => $m->product->sku ?? null,
                'external_product_id' => $m->external_product_id,
                'error_message'       => $m->error_message,
                'failed_at'           => $m->updated_at,
            ]);

        $pendingProducts = $this->repo->getPendingProducts($shop->id)
            ->map(fn ($m) => [
                'product_id'   => $m->product_id,
                'product_name' => $m->product->name ?? null,
                'sku'          => $m->product->sku ?? null,
                'queued_at'    => $m->created_at,
            ]);

        return array_merge($summary, [
            'recent_failures'  => $recentFailures,
            'pending_products' => $pendingProducts,
        ]);
    }

    public function getShopProducts(string $shopId, ?string $syncStatus): LengthAwarePaginator
    {
        $shop = $this->repo->findShopByShopId($shopId);
        if (!$shop) {
            return new LengthAwarePaginator([], 0, 10);
        }

        return $this->repo->getShopProducts($shop->id, $syncStatus);
    }

    private function buildShopSummary(object $shop): array
    {
        $stats        = $this->repo->getShopStats($shop->id);
        $total        = array_sum($stats);
        $lastSyncedAt = $this->repo->getLastSyncedAt($shop->id);
        $failed24h    = $this->repo->getFailedLast24h($shop->id);

        return [
            'channel_shop_id' => $shop->id,
            'shop_id'         => $shop->shop_id,
            'shop_name'       => $shop->shop_name,
            'channel'         => $shop->channel->name ?? null,
            'channel_code'    => $shop->channel->code ?? null,
            'is_active'       => $shop->is_active,
            'stats'           => [
                'total_linked' => $total,
                'synced'       => (int) ($stats['synced']      ?? 0),
                'pending'      => (int) ($stats['pending']     ?? 0),
                'syncing'      => (int) ($stats['syncing']     ?? 0),
                'failed'       => (int) ($stats['failed']      ?? 0),
                'deactivated'  => (int) ($stats['deactivated'] ?? 0),
            ],
            'last_synced_at'  => $lastSyncedAt,
            'failed_last_24h' => $failed24h,
        ];
    }
}
