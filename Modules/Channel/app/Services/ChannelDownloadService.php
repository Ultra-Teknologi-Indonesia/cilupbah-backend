<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class ChannelDownloadService
{
    public function __construct(
        protected ChannelShopRepository $channelShopRepository,
    ) {}

    public function download(string $channel, string $shopId, ?string $executedBy = null): DownloadTransaction
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId);

        $transaction = DownloadTransaction::create([
            'channel_shop_id' => $channelShopId,
            'executed_by' => $executedBy,
            'state' => DownloadTransaction::STATE_QUEUED,
        ]);

        DownloadProductsJob::dispatch($transaction->id, $channel, $shopId)->afterCommit();

        return $transaction;
    }

    public function downloadBulk(string $channel, array $shopIds, ?string $executedBy = null): array
    {
        $this->assertSupported($channel);

        $transactions = [];
        foreach ($shopIds as $shopId) {
            try {
                $transactions[] = $this->download($channel, $shopId, $executedBy);
            } catch (\Throwable $e) {
                Log::warning("Download massal: lewati toko {$shopId} — {$e->getMessage()}");
            }
        }

        return $transactions;
    }

    public function pull(string $channel, string $shopId): int
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        try {
            $count = ($this->pullerFor($channel))($shopId);
        } catch (\Throwable $e) {
            ProductSyncLog::record([
                'channel_shop_id' => $channelShopId,
                'action' => ProductSyncLog::ACTION_DOWNLOAD,
                'status' => ProductSyncLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        ProductSyncLog::record([
            'channel_shop_id' => $channelShopId,
            'action' => ProductSyncLog::ACTION_DOWNLOAD,
            'status' => ProductSyncLog::STATUS_SUCCESS,
            'response' => ['pulled_count' => $count],
        ]);

        return $count;
    }

    public function searchProducts(string $channel, string $shopId, string $query): array
    {
        $this->assertSupported($channel);

        $results = match (strtolower($channel)) {
            'tiktok' => app(TikTokProductService::class)->searchProducts($shopId, $query),
            'lazada' => app(LazadaProductService::class)->searchProducts($shopId, $query),
            'shopee' => app(ShopeeProductService::class)->searchProducts($shopId, $query),
            default => [],
        };

        return $this->flagDownloaded($shopId, $results);
    }

    protected function flagDownloaded(string $shopId, array $results): array
    {
        if (empty($results)) {
            return $results;
        }

        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        $downloaded = [];
        if ($channelShopId) {
            $ids = array_values(array_filter(array_map(
                fn ($r) => isset($r['external_product_id']) ? (string) $r['external_product_id'] : null,
                $results
            )));

            if (! empty($ids)) {
                $downloaded = array_flip(
                    ProductChannelMapping::where('channel_shop_id', $channelShopId)
                        ->whereIn('external_product_id', $ids)
                        ->pluck('external_product_id')
                        ->map(fn ($v) => (string) $v)
                        ->all()
                );
            }
        }

        return array_map(function ($r) use ($downloaded) {
            $r['already_downloaded'] = isset($downloaded[(string) ($r['external_product_id'] ?? '')]);

            return $r;
        }, $results);
    }

    public function downloadProduct(string $channel, string $shopId, string $externalProductId): bool
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId);

        $ok = match (strtolower($channel)) {
            'tiktok' => app(TikTokProductService::class)->pullProductById($shopId, $externalProductId),
            'lazada' => app(LazadaProductService::class)->pullProductById($shopId, $externalProductId),
            'shopee' => app(ShopeeProductService::class)->pullProductById($shopId, $externalProductId),
            default => false,
        };

        if (! $ok) {
            ProductSyncLog::record([
                'channel_shop_id' => $channelShopId,
                'action' => ProductSyncLog::ACTION_DOWNLOAD,
                'status' => ProductSyncLog::STATUS_FAILED,
                'error_message' => "Produk {$externalProductId} tidak ditemukan atau gagal diunduh",
            ]);

            throw new \RuntimeException('Produk tidak ditemukan atau gagal diunduh', 422);
        }

        return true;
    }

    protected function assertSupported(string $channel): void
    {
        if (! in_array(strtolower($channel), ['tiktok', 'lazada', 'shopee'], true)) {
            throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422);
        }
    }

    protected function requireChannelShopId(string $shopId): string
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        if (! $channelShopId) {
            throw new \RuntimeException('Toko tidak ditemukan', 422);
        }

        return $channelShopId;
    }

    protected function pullerFor(string $channel): callable
    {
        return match (strtolower($channel)) {
            'tiktok' => fn (string $shopId) => app(TikTokProductService::class)->pullProducts($shopId),
            'lazada' => fn (string $shopId) => app(LazadaProductService::class)->pullProducts($shopId),
            'shopee' => fn (string $shopId) => app(ShopeeProductService::class)->pullProducts($shopId),
            default => throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422),
        };
    }
}
