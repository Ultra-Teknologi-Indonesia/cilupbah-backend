<?php

namespace Modules\Channel\Services;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\ChunkedDownloadable;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Jobs\ProcessDownloadChunkJob;
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

        $debounceKey = 'channel_full_pull_debounce:' . strtolower($channel) . ":{$shopId}";
        if (! Cache::add($debounceKey, true, 10)) {
            $recent = DownloadTransaction::where('channel_shop_id', $channelShopId)
                ->whereIn('state', [DownloadTransaction::STATE_QUEUED, DownloadTransaction::STATE_DOWNLOADING])
                ->latest('created_at')
                ->first();

            if ($recent) {
                return $recent;
            }
        }

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

    public function pull(string $channel, string $shopId, ?\Closure $onProgress = null): int
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        try {
            $count = ($this->pullerFor($channel, $onProgress))($shopId);
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

    public function searchProducts(string $channel, string $shopId, string $query, int $offset = 0, int $limit = 20): array
    {
        $this->assertSupported($channel);
        $ch = strtolower($channel);

        if ($ch === 'shopee') {
            $paged = app(ShopeeProductService::class)->searchProductsPaged($shopId, $query, $offset, $limit);

            return [
                'items' => $this->flagDownloaded($shopId, $paged['items']),
                'next_offset' => $paged['next_offset'],
                'has_more' => $paged['has_more'],
            ];
        }

        if ($offset > 0) {
            return ['items' => [], 'next_offset' => null, 'has_more' => false];
        }

        $results = match ($ch) {
            'tiktok' => app(TikTokProductService::class)->searchProducts($shopId, $query),
            'lazada' => app(LazadaProductService::class)->searchProducts($shopId, $query),
            'woocommerce' => app(WooCommerceProductService::class)->searchProducts($shopId, $query),
            default => [],
        };

        return [
            'items' => $this->flagDownloaded($shopId, $results),
            'next_offset' => null,
            'has_more' => false,
        ];
    }

    public function searchUnifiedProducts(string $query, array $shopIds = [], int $limitPerShop = 20): array
    {
        $query = trim($query);

        $shopQuery = \Modules\Channel\Models\ChannelShop::with('channel')
            ->whereNull('disconnected_at')
            ->where('is_active', true)
            ->whereHas('channel', fn ($q) => $q->whereIn('code', ['tiktok', 'shopee', 'lazada', 'woocommerce']));

        if (! empty($shopIds)) {
            $shopQuery->whereIn('shop_id', $shopIds);
        }

        $shops = $shopQuery->get();

        $allItems = [];
        $failedStores = [];

        foreach ($shops as $shop) {
            $channelCode = strtolower($shop->channel->code ?? '');
            try {
                if ($channelCode === 'shopee') {
                    $paged = app(ShopeeProductService::class)->searchProductsPaged($shop->shop_id, $query, 0, $limitPerShop);
                    $shopItems = $paged['items'] ?? [];
                } elseif ($channelCode === 'tiktok') {
                    $shopItems = app(TikTokProductService::class)->searchProducts($shop->shop_id, $query);
                } elseif ($channelCode === 'lazada') {
                    $shopItems = app(LazadaProductService::class)->searchProducts($shop->shop_id, $query);
                } elseif ($channelCode === 'woocommerce') {
                    $shopItems = app(WooCommerceProductService::class)->searchProducts($shop->shop_id, $query);
                } else {
                    $shopItems = [];
                }

                foreach ($shopItems as $item) {
                    $item['shop_id'] = $shop->shop_id;
                    $item['shop_name'] = $shop->shop_name;
                    $item['channel_code'] = $channelCode;
                    $item['channel_name'] = $shop->channel->name ?? ucfirst($channelCode);
                    $allItems[] = $item;
                }
            } catch (\Throwable $e) {
                Log::warning("Unified channel search error for shop {$shop->shop_id} ({$channelCode}): " . $e->getMessage());
                $failedStores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'channel' => $channelCode,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (empty($allItems)) {
            return [
                'items' => [],
                'meta' => [
                    'total_found' => 0,
                    'total_stores' => $shops->count(),
                    'failed_stores' => $failedStores,
                ],
            ];
        }

        // Batch query product mappings in ONE SQL query
        $allExtIds = collect($allItems)->pluck('external_product_id')->filter()->unique()->values()->all();
        $shopIdMap = $shops->pluck('id', 'shop_id')->all();

        $existingMappings = ProductChannelMapping::with(['product:id,name,sku'])
            ->whereIn('channel_shop_id', $shops->pluck('id'))
            ->whereIn('external_product_id', $allExtIds)
            ->whereHas('product')
            ->get()
            ->keyBy(fn ($m) => $m->channel_shop_id . ':' . $m->external_product_id);

        foreach ($allItems as &$it) {
            $dbShopId = $shopIdMap[$it['shop_id']] ?? null;
            $mappingKey = $dbShopId ? ($dbShopId . ':' . ($it['external_product_id'] ?? '')) : null;
            $mapping = $mappingKey ? $existingMappings->get($mappingKey) : null;

            $it['already_downloaded'] = (bool) $mapping;
            $it['master_product_id'] = $mapping?->product_id;
            $it['master_product_name'] = $mapping?->product?->name;
            $it['master_product_sku'] = $mapping?->product?->sku;
        }
        unset($it);

        // Sort: not downloaded first, exact SKU matches higher
        usort($allItems, function ($a, $b) use ($query) {
            $aDown = ($a['already_downloaded'] ?? false) ? 1 : 0;
            $bDown = ($b['already_downloaded'] ?? false) ? 1 : 0;
            if ($aDown !== $bDown) {
                return $aDown <=> $bDown;
            }

            if (! empty($query)) {
                $aExact = (strcasecmp($a['seller_sku'] ?? '', $query) === 0) ? 0 : 1;
                $bExact = (strcasecmp($b['seller_sku'] ?? '', $query) === 0) ? 0 : 1;
                if ($aExact !== $bExact) {
                    return $aExact <=> $bExact;
                }
            }

            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
        });

        return [
            'items' => $allItems,
            'meta' => [
                'total_found' => count($allItems),
                'total_stores' => $shops->count(),
                'failed_stores' => $failedStores,
            ],
        ];
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
                        ->whereHas('product')
                        ->pluck('external_product_id')
                        ->map(fn ($v) => (string) $v)
                        ->all()
                );
            }
        }

        $flagged = array_map(function ($r) use ($downloaded) {
            $r['already_downloaded'] = isset($downloaded[(string) ($r['external_product_id'] ?? '')]);

            return $r;
        }, $results);

        usort(
            $flagged,
            fn ($a, $b) => (($a['already_downloaded'] ?? false) ? 1 : 0) <=> (($b['already_downloaded'] ?? false) ? 1 : 0)
        );

        return $flagged;
    }

    public function downloadProductManual(string $channel, string $shopId, string $externalProductId, ?string $executedBy = null): DownloadTransaction
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId);

        $transaction = DownloadTransaction::create([
            'channel_shop_id' => $channelShopId,
            'executed_by' => $executedBy,
            'state' => DownloadTransaction::STATE_DOWNLOADING,
            'all_product' => 1,
        ]);

        try {
            $this->downloadProduct($channel, $shopId, $externalProductId);
        } catch (\Throwable $e) {
            $transaction->markFailed($e->getMessage());

            throw $e;
        }

        $transaction->markDone(1, 0);

        return $transaction;
    }

    public function downloadProduct(string $channel, string $shopId, string $externalProductId): bool
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId);

        $ok = match (strtolower($channel)) {
            'tiktok' => app(TikTokProductService::class)->pullProductById($shopId, $externalProductId),
            'lazada' => app(LazadaProductService::class)->pullProductById($shopId, $externalProductId),
            'shopee' => app(ShopeeProductService::class)->pullProductById($shopId, $externalProductId),
            'woocommerce' => app(WooCommerceProductService::class)->pullProductById($shopId, $externalProductId),
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

    public function downloadProductDebounced(string $channel, string $shopId, string $externalProductId, int $seconds = 20): bool
    {
        $key = 'channel_pull_debounce:' . strtolower($channel) . ":{$shopId}:{$externalProductId}";

        if (! Cache::add($key, true, $seconds)) {
            return true;
        }

        return $this->downloadProduct($channel, $shopId, $externalProductId);
    }

    protected function assertSupported(string $channel): void
    {
        if (! in_array(strtolower($channel), ['tiktok', 'lazada', 'shopee', 'woocommerce'], true)) {
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

    protected function pullerFor(string $channel, ?\Closure $onProgress = null): callable
    {
        return match (strtolower($channel)) {
            'tiktok' => fn (string $shopId) => app(TikTokProductService::class)->pullProducts($shopId, $onProgress),
            'lazada' => fn (string $shopId) => app(LazadaProductService::class)->pullProducts($shopId, $onProgress),
            'shopee' => fn (string $shopId) => app(ShopeeProductService::class)->pullProducts($shopId, $onProgress),
            'woocommerce' => fn (string $shopId) => app(WooCommerceProductService::class)->pullProducts($shopId, $onProgress),
            default => throw new \RuntimeException("Channel '{$channel}' belum didukung untuk download", 422),
        };
    }

    protected function pullerServiceFor(string $channel): ?ChunkedDownloadable
    {
        $service = match (strtolower($channel)) {
            'shopee' => app(ShopeeProductService::class),
            'tiktok' => app(TikTokProductService::class),
            'lazada' => app(LazadaProductService::class),
            'woocommerce' => app(WooCommerceProductService::class),
            default => null,
        };

        return $service instanceof ChunkedDownloadable ? $service : null;
    }

    public function supportsBatch(string $channel): bool
    {
        $enabled = array_filter(array_map('trim', explode(',', (string) env('DOWNLOAD_BATCH_CHANNELS', ''))));

        return in_array(strtolower($channel), $enabled, true)
            && $this->pullerServiceFor($channel) !== null;
    }

    public function downloadChunk(string $channel, string $shopId, array $externalIds): array
    {
        $puller = $this->pullerServiceFor($channel);
        if (! $puller) {
            return ['downloaded' => 0, 'failed' => count($externalIds)];
        }

        return $puller->downloadProductIds($shopId, $externalIds);
    }

    public function dispatchBatched(DownloadTransaction $transaction, string $channel, string $shopId): void
    {
        $puller = $this->pullerServiceFor($channel);
        if (! $puller) {
            $transaction->markFailed("Channel '{$channel}' tak mendukung batch download");

            return;
        }

        $ids = $puller->listProductIds($shopId);

        $transaction->update([
            'state' => DownloadTransaction::STATE_DOWNLOADING,
            'all_product' => count($ids),
            'total_downloaded' => 0,
            'total_failed' => 0,
            'progress_percent' => $ids === [] ? 100 : 0,
        ]);

        if ($ids === []) {
            $transaction->update(['state' => DownloadTransaction::STATE_DONE, 'progress_percent' => 100]);

            return;
        }

        $chunkSize = max(1, (int) env('DOWNLOAD_BATCH_CHUNK', 50));
        $trxId = $transaction->id;

        $jobs = array_map(
            fn (array $chunk) => new ProcessDownloadChunkJob($trxId, $channel, $shopId, $chunk),
            array_chunk($ids, $chunkSize),
        );

        Bus::batch($jobs)
            ->name("download:{$trxId}")
            ->onConnection('redis-long')
            ->onQueue(config('queue.names.downloads'))
            ->finally(function (Batch $batch) use ($trxId) {
                $t = DownloadTransaction::find($trxId);
                if (! $t) {
                    return;
                }

                $t->update([
                    'state' => $batch->hasFailures() && (int) $t->total_downloaded === 0
                        ? DownloadTransaction::STATE_FAILED
                        : DownloadTransaction::STATE_DONE,
                    'progress_percent' => 100,
                ]);
            })
            ->dispatch();
    }
}
