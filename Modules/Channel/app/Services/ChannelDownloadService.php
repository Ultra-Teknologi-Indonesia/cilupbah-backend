<?php

namespace Modules\Channel\Services;

use Illuminate\Bus\Batch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Contracts\ChunkedDownloadable;
use Modules\Channel\Jobs\DownloadProductsJob;
use Modules\Channel\Jobs\DownloadSingleProductJob;
use Modules\Channel\Jobs\ProcessDownloadChunkJob;
use Modules\Channel\Models\DownloadTransaction;
use Modules\Channel\Models\ChannelShop;
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
        $channelShopId = $this->requireChannelShopId($shopId, $channel);

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
        $channelShopId = $this->requireChannelShopId($shopId, $channel);

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
            $paged = app(ShopeeProductService::class)->searchProductsPaged(
                $shopId,
                $query,
                $offset,
                $limit,
                (int) config('channel.search_remote_timeout_seconds', 8),
            );

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
            'tiktok' => app(TikTokProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
            'lazada' => app(LazadaProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
            'woocommerce' => app(WooCommerceProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
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
        $limitPerShop = max(1, min(50, $limitPerShop));
        $shopIds = array_slice(
            array_values(array_unique(array_filter(array_map('strval', $shopIds)))),
            0,
            100,
        );

        $shopQuery = ChannelShop::with('channel')
            ->whereNull('disconnected_at')
            ->where('is_active', true)
            ->whereHas('channel', fn ($q) => $q->whereIn('code', ['tiktok', 'shopee', 'lazada', 'woocommerce']));

        if (! empty($shopIds)) {
            $shopQuery->whereIn('shop_id', $shopIds);
        }

        $shops = $shopQuery->get();

        $allItems = [];
        $failedStores = [];
        $remoteTasks = [];

        foreach ($shops as $shop) {
            $channelCode = strtolower($shop->channel->code ?? '');
            $remoteTasks[(string) $shop->id] = $this->buildRemoteSearchTask(
                $channelCode,
                (string) $shop->shop_id,
                $query,
                $limitPerShop,
            );
        }

        foreach ($this->runRemoteSearchTasks($remoteTasks) as $shopKey => $remoteResult) {
            $shop = $shops->firstWhere('id', $shopKey);
            if (! $shop) {
                continue;
            }

            if (! ($remoteResult['ok'] ?? false)) {
                $channelCode = strtolower($shop->channel->code ?? '');
                Log::warning("Unified channel search error for shop {$shop->shop_id} ({$channelCode}): " . ($remoteResult['error'] ?? 'Unknown error'), [
                    'shop_id' => $shop->shop_id,
                    'channel' => $channelCode,
                    'exception' => $remoteResult['exception'] ?? null,
                ]);

                $failedStores[] = [
                    'shop_id' => $shop->shop_id,
                    'shop_name' => $shop->shop_name,
                    'channel' => $channelCode,
                    'error' => $remoteResult['error'] ?? 'Pencarian toko gagal',
                    'error_code' => $remoteResult['error_code'] ?? 'UPSTREAM_ERROR',
                    'retryable' => (bool) ($remoteResult['retryable'] ?? true),
                ];
                continue;
            }

            $channelCode = strtolower($shop->channel->code ?? '');
            $shopItems = array_slice($remoteResult['items'] ?? [], 0, max(1, $limitPerShop));

            foreach ($shopItems as $item) {
                $item['shop_id'] = $shop->shop_id;
                $item['shop_name'] = $shop->shop_name;
                $item['channel_code'] = $channelCode;
                $item['channel_name'] = $shop->channel->name ?? ucfirst($channelCode);
                $allItems[] = $item;
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

        $allExtIds = collect($allItems)->pluck('external_product_id')->filter()->unique()->values()->all();
        $shopIdMap = $shops->pluck('id', 'shop_id')->all();

        $existingMappings = ProductChannelMapping::with(['product' => fn ($query) => $query
                ->withTrashed()
                ->select('id', 'name', 'sku', 'is_bundle', 'is_active', 'deleted_at')])
            ->whereIn('channel_shop_id', $shops->pluck('id'))
            ->whereIn('external_product_id', $allExtIds)
            ->whereHas('product', fn ($query) => $query->withTrashed())
            ->get()
            ->groupBy(fn ($m) => $m->channel_shop_id . ':' . $m->external_product_id);

        foreach ($allItems as &$it) {
            $dbShopId = $shopIdMap[$it['shop_id']] ?? null;
            $mappingKey = $dbShopId ? ($dbShopId . ':' . ($it['external_product_id'] ?? '')) : null;
            $mappings = $mappingKey ? $existingMappings->get($mappingKey, collect()) : collect();

            $it = $this->decorateDownloadStatus($it, $mappings);
        }
        unset($it);

        usort($allItems, function ($a, $b) use ($query) {
            $aDown = ($a['already_downloaded'] ?? false) ? 1 : 0;
            $bDown = ($b['already_downloaded'] ?? false) ? 1 : 0;
            if ($aDown !== $bDown) {
                return $aDown <=> $bDown;
            }

            if (! empty($query)) {
                $aExact = $this->isExactSkuMatch($a, $query) ? 0 : 1;
                $bExact = $this->isExactSkuMatch($b, $query) ? 0 : 1;
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

        $downloaded = collect();
        if ($channelShopId) {
            $ids = array_values(array_filter(array_map(
                fn ($r) => isset($r['external_product_id']) ? (string) $r['external_product_id'] : null,
                $results
            )));

            if (! empty($ids)) {
                $downloaded = ProductChannelMapping::with(['product' => fn ($query) => $query
                        ->withTrashed()
                        ->select('id', 'name', 'sku', 'is_bundle', 'is_active', 'deleted_at')])
                    ->where('channel_shop_id', $channelShopId)
                    ->whereIn('external_product_id', $ids)
                    ->whereHas('product', fn ($query) => $query->withTrashed())
                    ->get()
                    ->groupBy(fn ($mapping) => (string) $mapping->external_product_id);
            }
        }

        $flagged = array_map(function ($r) use ($downloaded) {
            $mappings = $downloaded->get((string) ($r['external_product_id'] ?? ''), collect());

            return $this->decorateDownloadStatus($r, $mappings);
        }, $results);

        usort(
            $flagged,
            fn ($a, $b) => (($a['already_downloaded'] ?? false) ? 1 : 0) <=> (($b['already_downloaded'] ?? false) ? 1 : 0)
        );

        return $flagged;
    }

    protected function decorateDownloadStatus(array $item, Collection $mappings): array
    {
        $usableMappings = $mappings->filter(function (ProductChannelMapping $mapping): bool {
            return $mapping->product
                && ! $mapping->product->trashed()
                && $mapping->product->is_active
                && ! in_array($mapping->sync_status, [
                    ProductChannelMapping::STATUS_FAILED,
                    ProductChannelMapping::STATUS_DEACTIVATED,
                ], true);
        });
        $regularMapping = $usableMappings->first(
            fn (ProductChannelMapping $mapping): bool => ! $mapping->product->is_bundle
        );
        $bundleMappings = $usableMappings->filter(
            fn (ProductChannelMapping $mapping): bool => (bool) $mapping->product->is_bundle
        );
        $deletedMapping = $mappings->first(
            fn (ProductChannelMapping $mapping): bool => $mapping->product?->trashed()
        );

        $hasRegularMapping = $regularMapping !== null;
        $hasBundleMapping = $bundleMappings->isNotEmpty();
        $action = $hasRegularMapping
            ? 'none'
            : ($hasBundleMapping ? 'sync_bundle' : 'download');

        $item['download_action'] = $action;
        $item['master_status'] = $deletedMapping && ! $hasBundleMapping && ! $hasRegularMapping
            ? 'deleted'
            : ($hasBundleMapping && ! $hasRegularMapping ? 'bundle' : ($hasRegularMapping ? 'active' : 'missing'));
        $item['already_downloaded'] = $hasRegularMapping;
        $item['mapping_status'] = $regularMapping?->sync_status ?? $bundleMappings->first()?->sync_status;
        $item['master_product_id'] = $regularMapping?->product_id;
        $item['master_product_name'] = $regularMapping?->product?->name;
        $item['master_product_sku'] = $regularMapping?->product?->sku;
        $item['bundle_mapping_count'] = $bundleMappings->count();
        $item['regular_mapping_count'] = $hasRegularMapping ? 1 : 0;
        $item['has_bundle_mapping'] = $hasBundleMapping;
        $item['has_regular_mapping'] = $hasRegularMapping;

        return $item;
    }

    protected function buildRemoteSearchTask(string $channel, string $shopId, string $query, int $limit): callable
    {
        $serviceClass = static::class;
        $cacheKey = $this->searchCacheKey($channel, $shopId, $query);

        return static function () use ($serviceClass, $channel, $shopId, $query, $limit, $cacheKey): array {
            try {
                $items = Cache::remember(
                    $cacheKey,
                    now()->addSeconds(max(1, (int) config('channel.search_cache_ttl_seconds', 30))),
                    fn (): array => app($serviceClass)->searchRemoteShop($channel, $shopId, $query, $limit),
                );

                return ['ok' => true, 'items' => is_array($items) ? $items : []];
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'items' => [],
                    'exception' => get_class($e),
                    ...static::safeRemoteSearchError($channel, $e),
                ];
            }
        };
    }

    protected static function safeRemoteSearchError(string $channel, \Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());
        $label = ucfirst($channel);

        if (str_contains($message, 'curl error 28') || str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return [
                'error_code' => 'UPSTREAM_TIMEOUT',
                'retryable' => true,
                'error' => "{$label} tidak merespons tepat waktu. Silakan coba lagi.",
            ];
        }

        return [
            'error_code' => 'UPSTREAM_ERROR',
            'retryable' => true,
            'error' => "Pencarian produk di {$label} gagal. Silakan coba lagi.",
        ];
    }

    protected function runRemoteSearchTasks(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        $results = [];
        $maxParallel = max(1, (int) config('channel.search_max_parallel_stores', 8));

        foreach (array_chunk($tasks, $maxParallel, true) as $batch) {
            if (count($batch) === 1 || app()->runningUnitTests()) {
                foreach ($batch as $key => $task) {
                    $results[$key] = $task();
                }

                continue;
            }

            try {
                $results += Concurrency::driver((string) config('channel.search_concurrency_driver', 'process'))->run($batch);
            } catch (\Throwable $e) {

                Log::warning('Unified channel search concurrency unavailable; using isolated fallback', [
                    'task_count' => count($batch),
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);

                foreach ($batch as $key => $task) {
                    $results[$key] = $task();
                }
            }
        }

        return $results;
    }

    protected function isExactSkuMatch(array $item, string $query): bool
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return false;
        }

        $skus = collect($item['seller_skus'] ?? [])
            ->merge([$item['seller_sku'] ?? null, $item['master_product_sku'] ?? null])
            ->filter(fn ($sku) => is_string($sku) && trim($sku) !== '');

        return $skus->contains(fn (string $sku): bool => mb_strtolower(trim($sku)) === $needle);
    }

    protected function searchCacheKey(string $channel, string $shopId, string $query): string
    {
        return 'channel_catalog_search:v2:' . strtolower($channel) . ':' . $shopId . ':' . sha1(mb_strtolower(trim($query)));
    }

    protected function searchRemoteShop(string $channel, string $shopId, string $query, int $limit): array
    {
        return match ($channel) {
            'shopee' => trim($query) !== ''
                ? app(ShopeeProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit)
                : (app(ShopeeProductService::class)->searchProductsPaged($shopId, $query, 0, $limit, (int) config('channel.search_remote_timeout_seconds', 8))['items'] ?? []),
            'tiktok' => app(TikTokProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
            'lazada' => app(LazadaProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
            'woocommerce' => app(WooCommerceProductService::class)->searchProducts($shopId, $query, (int) config('channel.search_remote_timeout_seconds', 8), $limit),
            default => [],
        };
    }

    public function downloadProductManual(string $channel, string $shopId, string $externalProductId, ?string $executedBy = null): DownloadTransaction
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId, $channel);

        $active = DownloadTransaction::query()
            ->where('channel_shop_id', $channelShopId)
            ->where('external_product_id', $externalProductId)
            ->whereIn('state', [DownloadTransaction::STATE_QUEUED, DownloadTransaction::STATE_DOWNLOADING])
            ->latest('created_at')
            ->first();

        if ($active) {
            return $active;
        }

        try {
            $transaction = DownloadTransaction::create([
                'channel_shop_id' => $channelShopId,
                'external_product_id' => $externalProductId,
                'executed_by' => $executedBy,
                'state' => DownloadTransaction::STATE_QUEUED,
                'all_product' => 1,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            $transaction = DownloadTransaction::query()
                ->where('channel_shop_id', $channelShopId)
                ->where('external_product_id', $externalProductId)
                ->whereIn('state', [DownloadTransaction::STATE_QUEUED, DownloadTransaction::STATE_DOWNLOADING])
                ->latest('created_at')
                ->first();

            if (! $transaction) {
                throw $e;
            }

            return $transaction;
        }

        DownloadSingleProductJob::dispatch(
            $transaction->id,
            $channel,
            $shopId,
            $externalProductId,
        )->afterCommit();

        return $transaction;
    }

    public function downloadProduct(string $channel, string $shopId, string $externalProductId): bool
    {
        $this->assertSupported($channel);
        $channelShopId = $this->requireChannelShopId($shopId, $channel);

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

    protected function requireChannelShopId(string $shopId, ?string $channel = null): string
    {
        $channelShopId = $this->channelShopRepository->getIdByShopId($shopId);

        if (! $channelShopId) {
            throw new \RuntimeException('Toko tidak ditemukan', 422);
        }

        if ($channel !== null && ! ChannelShop::query()
            ->whereKey($channelShopId)
            ->whereHas('channel', fn ($query) => $query->whereRaw('LOWER(code) = ?', [strtolower($channel)]))
            ->exists()) {
            throw new \RuntimeException('Toko tidak sesuai dengan channel yang dipilih', 422);
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
