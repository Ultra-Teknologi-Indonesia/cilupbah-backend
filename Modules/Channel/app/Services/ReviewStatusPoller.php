<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Product\Models\ProductChannelMapping;

class ReviewStatusPoller
{
    public function __construct(
        protected TikTokProductService $tiktok,
        protected LazadaProductService $lazada,
        protected ShopeeProductService $shopee,
    ) {}

    public function pollAll(): array
    {
        $summary = ['shops' => 0, 'checked' => 0, 'updated' => 0];

        ChannelShop::with('channel')
            ->whereNull('disconnected_at')
            ->whereHas('channel', fn ($q) => $q->whereIn('code', ['tiktok', 'lazada', 'shopee']))
            ->each(function (ChannelShop $shop) use (&$summary) {
                $r = $this->pollShop($shop);
                $summary['shops']++;
                $summary['checked'] += $r['checked'];
                $summary['updated'] += $r['updated'];
            });

        return $summary;
    }

    public function pollShop(ChannelShop $shop): array
    {
        $shop->loadMissing('channel');
        $code = $shop->channel?->code;

        $service = match ($code) {
            'tiktok' => $this->tiktok,
            'lazada' => $this->lazada,
            'shopee' => $this->shopee,
            default => null,
        };

        if ($service === null) {
            return ['checked' => 0, 'updated' => 0];
        }

        $checked = 0;
        $updated = 0;

        try {
            $service->eachProductStatusPage(
                $shop->shop_id,
                function (array $statuses) use ($shop, $code, &$checked, &$updated) {
                    [$c, $u] = $this->applyPage($shop, $code, $statuses);
                    $checked += $c;
                    $updated += $u;
                },
            );
        } catch (\Throwable $e) {
            Log::warning('Polling status review gagal', ['shop_id' => $shop->shop_id, 'error' => $e->getMessage()]);
        }

        return ['checked' => $checked, 'updated' => $updated];
    }

    private function applyPage(ChannelShop $shop, ?string $code, array $statuses): array
    {
        $mappings = ProductChannelMapping::where('channel_shop_id', $shop->id)
            ->whereIn('external_product_id', array_keys($statuses))
            ->whereIn('sync_status', [
                ProductChannelMapping::STATUS_PENDING,
                ProductChannelMapping::STATUS_SYNCING,
                ProductChannelMapping::STATUS_IN_REVIEW,
                ProductChannelMapping::STATUS_SYNCED,
            ])
            ->get();

        $checked = 0;
        $updated = 0;

        foreach ($mappings as $mapping) {
            $info = $statuses[$mapping->external_product_id] ?? null;
            if (! $info) {
                continue;
            }
            $checked++;

            $target = $this->mapStatus($code, $info['status']);
            if ($target === null || $target === $mapping->sync_status) {
                continue;
            }

            $this->apply($mapping, $target, $info['reason'] ?? null);
            $updated++;
        }

        return [$checked, $updated];
    }

    protected function mapStatus(?string $code, string $raw): ?string
    {
        if ($code === 'tiktok') {
            return match ($raw) {
                '2' => ProductChannelMapping::STATUS_IN_REVIEW,
                '4', 'ACTIVATE' => ProductChannelMapping::STATUS_SYNCED,
                '3' => ProductChannelMapping::STATUS_REJECTED,
                '5', '6' => ProductChannelMapping::STATUS_DEACTIVATED,
                default => null,
            };
        }

        if ($code === 'lazada') {
            return match (true) {
                in_array($raw, ['approved', 'active'], true) => ProductChannelMapping::STATUS_SYNCED,
                in_array($raw, ['pending', 'reviewing', 'pending_qc'], true) => ProductChannelMapping::STATUS_IN_REVIEW,
                in_array($raw, ['rejected', 'suspended', 'deleted', 'inactive'], true) => ProductChannelMapping::STATUS_REJECTED,
                default => null,
            };
        }

        if ($code === 'shopee') {
            return match ($raw) {
                'normal' => ProductChannelMapping::STATUS_SYNCED,
                'banned' => ProductChannelMapping::STATUS_REJECTED,
                'deleted', 'unlist' => ProductChannelMapping::STATUS_DEACTIVATED,
                default => null,
            };
        }

        return null;
    }

    protected function apply(ProductChannelMapping $mapping, string $target, ?string $reason): void
    {
        match ($target) {
            ProductChannelMapping::STATUS_SYNCED => $mapping->markApproved(),
            ProductChannelMapping::STATUS_IN_REVIEW => $mapping->markInReview(),
            ProductChannelMapping::STATUS_REJECTED => $mapping->markRejected($reason ?: 'Ditolak marketplace'),
            ProductChannelMapping::STATUS_DEACTIVATED => $mapping->update([
                'sync_status' => ProductChannelMapping::STATUS_DEACTIVATED,
            ]),
            default => null,
        };
    }
}
