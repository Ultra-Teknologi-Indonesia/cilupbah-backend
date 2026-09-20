<?php

namespace Modules\Channel\Jobs;

use App\Support\QueueFailureRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\ChannelStockSyncOutboxService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\TikTokAuthService;
use Modules\Channel\Support\ChannelErrorClassifier;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Channel\Support\UploadErrorPresenter;
use Modules\Product\Jobs\RecomputeProductChannelValidationJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelDraft;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class SyncProductToChannelJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 12;

    public int $timeout = 300;

    public array $backoff = [60, 300, 900, 1800];

    public int $maxExceptions = 5;

    public int $uniqueFor = 900;

    public string $productId;

    public string $channelShopId;

    public string $action;

    public ?array $attributeMapping;

    public ?string $draftId;

    public ?string $uploadLogId;

    public string $queueTier = 'critical';

    public ?string $channelMappingId;

    public ?string $stockOutboxId;

    public ?int $stockOutboxVersion;

    protected string $channelCodeResolved = '';

    protected bool $uploadResultRecorded = false;

    protected ?string $lastActionableFailure = null;

    private const STOCK_ACTIONS = ['sync_price_stock', 'sync_stock', 'sync_price'];

    private const PRICE_ACTIONS = ['sync_price_stock', 'sync_price'];

    public static function isStockAction(string $action): bool
    {
        return in_array($action, self::STOCK_ACTIONS, true);
    }

    public static function isPriceAction(string $action): bool
    {
        return in_array($action, self::PRICE_ACTIONS, true);
    }

    private function shopAllowsAction(ChannelShop $shop): bool
    {
        return match ($this->action) {
            'sync_stock' => (bool) $shop->stock_push_enabled,
            'sync_price' => (bool) $shop->price_push_enabled,
            'sync_price_stock' => (bool) $shop->stock_push_enabled && (bool) $shop->price_push_enabled,
            default => (bool) $shop->catalog_push_enabled,
        };
    }

    public function __construct(
        string $productId,
        string $channelShopId,
        string $action,
        ?array $attributeMapping = null,
        ?string $draftId = null,
        ?string $uploadLogId = null,
        string $queueTier = 'critical',
        ?string $channelMappingId = null,
        ?string $stockOutboxId = null,
        ?int $stockOutboxVersion = null,
    ) {
        $this->productId = $productId;
        $this->channelShopId = $channelShopId;
        $this->action = $action;
        $this->attributeMapping = $attributeMapping;
        $this->draftId = $draftId;
        $this->uploadLogId = $uploadLogId;
        $this->queueTier = $queueTier;
        $this->channelMappingId = $channelMappingId;
        $this->stockOutboxId = $stockOutboxId;
        $this->stockOutboxVersion = $stockOutboxVersion;

        $routing = self::isStockAction($action)
            ? config(
                $queueTier === 'bulk'
                    ? 'queue.routing.stock_default'
                    : 'queue.routing.channel_stock',
                [
                    'connection' => 'redis',
                    'queue' => $queueTier === 'bulk' ? 'stock-default' : 'channel-stock',
                ],
            )
            : config('queue.routing.channel_product', [
                'connection' => 'redis-long',
                'queue' => 'channel-product',
            ]);

        $this->onConnection($routing['connection'])
            ->onQueue($routing['queue']);
    }

    public function uniqueId(): string
    {
        $axis = self::isStockAction($this->action)
            ? 'stock:'.$this->action
            : 'catalog:'.$this->action;

        $requestId = $this->uploadLogId ?: $this->draftId;
        $listingScope = self::isStockAction($this->action)
            ? $this->channelMappingId
            : null;

        return implode(':', array_filter([
            'product-sync',
            $axis,
            $this->productId,
            $this->channelShopId,
            $listingScope,
            $this->queueTier,
            $requestId,
        ], static fn ($value) => $value !== null && $value !== ''));
    }

    public function middleware(): array
    {
        if ($this->isOutboxStockDelivery()) {
            return [];
        }

        $listingScope = self::isStockAction($this->action)
            ? ($this->channelMappingId ?: 'all-listings')
            : 'catalog';

        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping("product_sync:{$this->productId}:{$this->channelShopId}:{$listingScope}"))
                ->releaseAfter(60)
                ->expireAfter(config('channel.product_sync_overlap_lock_seconds', 360)),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function handle(AdapterFactory $factory): void
    {
        $stockOutbox = app(ChannelStockSyncOutboxService::class);

        if (app(ChannelSyncSettingService::class)->isPaused()) {
            $this->recordSkipped('Sinkronisasi channel sedang dinonaktifkan.');

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->defer(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    'Sinkronisasi channel sedang dinonaktifkan.',
                    60,
                );
            }

            return;
        }

        $product = self::isStockAction($this->action)
            ? Product::query()->where('is_active', true)->find($this->productId)
            : Product::with(['variants.channelMappings.channelMapping'])->find($this->productId);
        $shop = ChannelShop::with('channel')->find($this->channelShopId);

        if (! $product || ! $shop) {
            $this->recordUploadResult(false, 'Produk atau toko tidak ditemukan saat job upload diproses.');

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->skip(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    'Produk atau toko tidak ditemukan saat pengiriman stok diproses.',
                );
            }

            Log::warning('SyncProductToChannelJob skipped: Product or Shop not found.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
            ]);

            return;
        }

        if (! $this->shopAllowsAction($shop)) {
            $message = match ($this->action) {
                'sync_price' => 'Sinkronisasi harga untuk toko ini sedang dinonaktifkan.',
                'sync_stock', 'sync_price_stock' => 'Sinkronisasi stok untuk toko ini sedang dinonaktifkan.',
                default => 'Upload katalog untuk toko ini sedang dinonaktifkan.',
            };

            $this->recordSkipped($message);

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->skip($this->stockOutboxId, $this->stockOutboxVersion, $message);
            }

            Log::info('SyncProductToChannelJob skipped: sinkronisasi untuk toko ini dimatikan.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $this->action,
                'axis' => match ($this->action) {
                    'sync_price' => 'harga',
                    'sync_stock', 'sync_price_stock' => 'stok',
                    default => 'katalog',
                },
                'is_shadow_mode' => (bool) $shop->is_shadow_mode,
            ]);

            return;
        }

        if ($this->isOutboxStockDelivery()
            && ! $stockOutbox->shouldExecute($this->stockOutboxId, $this->stockOutboxVersion)) {
            return;
        }

        if (self::isStockAction($this->action) && $this->channelMappingId === null) {
            $this->fanOutStockSyncs();

            return;
        }

        $channelCode = $shop->channel->code ?? 'tiktok';
        $this->channelCodeResolved = $channelCode;

        $shop = $this->ensureFreshToken($shop, $channelCode);

        if (in_array($this->action, ['push', 'update'], true)
            && app(ChannelListingValidator::class)->lacksVariationAttributes($product)) {
            $message = "Produk multi-varian tanpa atribut variasi — wajib diisi sebelum upload ke {$channelCode}.";
            $this->lastActionableFailure = $message;
            $mapping = ProductChannelMapping::firstOrCreate([
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
            ]);
            $mapping->markAsFailed($message);
            $this->recordUploadResult(false, $message);
            $this->refreshChannelValidation();

            return;
        }

        $circuitKey = $this->circuitBreakerKey($channelCode);
        if (Cache::has($circuitKey)) {
            Log::warning("Circuit breaker is open for {$channelCode}. Re-queuing job.", [
                'product_id' => $this->productId,
            ]);
            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->defer(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    "Circuit breaker {$channelCode} masih aktif.",
                    300,
                );

                return;
            }

            $this->release(300);

            return;
        }

        $mapping = self::isStockAction($this->action)
            ? ProductChannelMapping::query()
                ->whereKey($this->channelMappingId)
                ->where('product_id', $this->productId)
                ->where('channel_shop_id', $this->channelShopId)
                ->with('variantMappings.variant')
                ->first()
            : ProductChannelMapping::firstOrCreate([
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
            ]);

        if (! $mapping) {
            Log::warning('SyncProductToChannelJob skipped: listing stok tidak ditemukan atau tidak cocok.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'channel_mapping_id' => $this->channelMappingId,
                'action' => $this->action,
            ]);

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->skip(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    'Listing tidak lagi cocok dengan produk atau toko saat diproses.',
                );
            }

            return;
        }

        if ($this->action === 'delete' && ! $mapping->external_product_id) {
            $mapping->delete();

            return;
        }

        if (self::isStockAction($this->action) && blank($mapping->external_product_id)) {
            if ($mapping->sync_status === ProductChannelMapping::STATUS_SYNCING) {
                $mapping->update([
                    'sync_status' => ProductChannelMapping::STATUS_PENDING,
                    'error_message' => null,
                ]);
            }

            Log::notice('SyncProductToChannelJob skipped: listing belum terhubung ke channel.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $this->action,
            ]);

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->skip(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    'Listing belum terhubung ke produk marketplace.',
                );
            }

            return;
        }

        if (self::isStockAction($this->action)
            && ChannelVariantMappingResolver::hasEnabledMappings($mapping)
            && ChannelVariantMappingResolver::enabledForListing($mapping)->isEmpty()) {
            Log::warning('SyncProductToChannelJob skipped: listing tidak memiliki varian master aktif yang valid.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'channel_mapping_id' => $mapping->id,
                'action' => $this->action,
            ]);

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->skip(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    'Listing tidak memiliki varian master aktif yang valid.',
                );
            }

            return;
        }

        $mapping->markAsSyncing();

        try {
            $adapter = $factory->make($channelCode);
        } catch (\Exception $e) {
            $message = "Adapter not found for channel {$channelCode}";
            $mapping->markAsFailed($message);
            $this->recordUploadResult(false, $message);
            $this->refreshChannelValidation();

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->fail($this->stockOutboxId, $this->stockOutboxVersion, $message);
            }

            return;
        }

        $externalId = $mapping->external_product_id ?? '';
        $result = ['success' => false, 'message' => 'Aksi tidak dikenal'];

        try {
            switch ($this->action) {
                case 'push':
                    if ($externalId) {
                        $result = $adapter->updateProduct($product, $shop, $externalId);
                    } else {
                        $result = $adapter->pushProduct($product, $shop, $this->attributeMapping);
                    }
                    break;
                case 'update':
                    if ($externalId) {
                        $result = $adapter->updateProduct($product, $shop, $externalId);
                    } else {
                        $result = $adapter->pushProduct($product, $shop, $this->attributeMapping);
                    }
                    break;
                case 'delete':
                    if ($externalId) {
                        $result = $adapter->deleteProduct($shop, $externalId);
                    }
                    break;
                case 'activate':
                    if ($externalId) {
                        $result = $adapter->activateProduct($shop, $externalId);
                    }
                    break;
                case 'deactivate':
                    if ($externalId) {
                        $result = $adapter->deactivateProduct($shop, $externalId);
                    }
                    break;
                case 'sync_price_stock':
                    if ($externalId) {
                        $result = $adapter->syncPriceAndStock($product, $shop, $externalId, $mapping);
                    }
                    break;
                case 'sync_price':
                    if ($externalId) {
                        $result = $adapter->syncPriceAndStock($product, $shop, $externalId, $mapping, true, false);
                    }
                    break;
                case 'sync_stock':
                    if ($externalId) {
                        $result = $adapter->syncStock($product, $shop, $externalId, $mapping);
                    }
                    break;
            }

            if ($result['success']) {
                $newExternalId = $result['external_product_id'] ?? $externalId;

                if (empty($externalId) && in_array($this->action, ['push', 'update'], true)) {
                    $mapping->markInReview($newExternalId);
                } else {
                    $mapping->markAsSynced($newExternalId);
                }
                $this->recordUploadResult(true, null, $result);

                if (! empty($result['skus'])) {
                    $this->updateVariantMappings($mapping, $product, $result['skus']);
                }

                if ($this->action === 'delete') {
                    $mapping->delete();
                }

                if ($this->draftId && in_array($this->action, ['push', 'update'], true)) {
                    try {
                        ProductChannelDraft::whereKey($this->draftId)->delete();
                    } catch (\Throwable $e) {
                        Log::warning('Gagal menghapus draft setelah upload sukses: '.$e->getMessage(), [
                            'draft_id' => $this->draftId,
                        ]);
                    }
                }

                if (! self::isStockAction($this->action)) {
                    $this->refreshChannelValidation();
                }

                if ($this->isOutboxStockDelivery()) {
                    $stockOutbox->succeed($this->stockOutboxId, $this->stockOutboxVersion);
                }

                $this->resetFailureState($channelCode);
            } else {
                $message = $result['message'] ?? 'Gagal mengeksekusi aksi';
                $this->lastActionableFailure = $message;

                $retryable = data_get($result, 'error.retryable');
                if ($retryable === null) {
                    $retryable = ChannelErrorClassifier::isRetryable(
                        $channelCode,
                        new \RuntimeException($message),
                    );
                }

                if (empty($externalId) && ! empty($result['external_product_id'])) {
                    $mapping->update(['external_product_id' => (string) $result['external_product_id']]);
                }

                $mapping->markAsFailed($message);
                $this->recordUploadResult(false, $message, $result);

                if (! $retryable) {
                    Log::notice('SyncProductToChannelJob stopped without retry for a deterministic failure.', [
                        'product_id' => $this->productId,
                        'channel_shop_id' => $this->channelShopId,
                        'channel_mapping_id' => $this->channelMappingId,
                        'channel' => $channelCode,
                        'action' => $this->action,
                        'reason' => $message,
                    ]);

                    if ($this->isOutboxStockDelivery()) {
                        $stockOutbox->fail($this->stockOutboxId, $this->stockOutboxVersion, $message);
                    }

                    return;
                }

                if ($this->isOutboxStockDelivery()) {
                    $mapping->update([
                        'sync_status' => ProductChannelMapping::STATUS_PENDING,
                        'error_message' => $mapping->error_message,
                    ]);
                    $stockOutbox->defer(
                        $this->stockOutboxId,
                        $this->stockOutboxVersion,
                        $message,
                        $stockOutbox->retryDelaySeconds($this->outboxAttemptCount()),
                    );

                    return;
                }

                throw new \Exception($message);
            }

        } catch (\Exception $e) {
            $this->lastActionableFailure = $e->getMessage();
            $mapping->markAsFailed($e->getMessage());
            if (! $this->uploadResultRecorded) {
                $this->recordUploadResult(false, $e->getMessage());
            }
            if (! self::isStockAction($this->action)) {
                $this->refreshChannelValidation();
            }
            $this->handleFailure($channelCode);

            if ($this->isOutboxStockDelivery()) {
                $stockOutbox->defer(
                    $this->stockOutboxId,
                    $this->stockOutboxVersion,
                    $e->getMessage(),
                    $stockOutbox->retryDelaySeconds($this->outboxAttemptCount()),
                );

                return;
            }

            throw $e;
        }
    }

    protected function ensureFreshToken(ChannelShop $shop, string $channelCode): ChannelShop
    {
        if (! $shop->token_expires_at || $shop->token_expires_at->isFuture()) {
            return $shop;
        }

        $authServices = [
            'shopee' => ShopeeAuthService::class,
            'tiktok' => TikTokAuthService::class,
            'lazada' => LazadaAuthService::class,
        ];

        $serviceClass = $authServices[$channelCode] ?? null;

        if (! $serviceClass) {
            return $shop;
        }

        try {
            app($serviceClass)->refreshStoreToken($shop->id);
            Log::info('Token refreshed before sync', ['shop_id' => $shop->shop_id, 'channel' => $channelCode]);

            return $shop->fresh();
        } catch (\Throwable $e) {
            Log::warning('Token refresh gagal sebelum sync, lanjut dengan token lama', [
                'shop_id' => $shop->shop_id,
                'channel' => $channelCode,
                'error' => $e->getMessage(),
            ]);

            return $shop;
        }
    }

    private function fanOutStockSyncs(): void
    {
        $dispatched = 0;

        ProductChannelMapping::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->whereNull('external_product_id')
            ->where('sync_status', ProductChannelMapping::STATUS_SYNCING)
            ->update([
                'sync_status' => ProductChannelMapping::STATUS_PENDING,
                'error_message' => null,
            ]);

        ProductChannelMapping::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->whereNotNull('external_product_id')
            ->where('external_product_id', '!=', '')
            ->select(['id', 'product_id', 'channel_shop_id'])
            ->lazyById(100)
            ->each(function (ProductChannelMapping $mapping) use (&$dispatched): void {
                app(ChannelStockSyncOutboxService::class)->request(
                    $mapping,
                    $this->action,
                    $this->queueTier,
                );

                $dispatched++;
            });

        Log::info('SyncProductToChannelJob fanned out stock sync per listing.', [
            'product_id' => $this->productId,
            'channel_shop_id' => $this->channelShopId,
            'action' => $this->action,
            'listings_dispatched' => $dispatched,
        ]);
    }

    protected function refreshChannelValidation(): void
    {
        RecomputeProductChannelValidationJob::dispatch($this->productId)->afterCommit();
    }

    protected function recordUploadResult(bool $success, ?string $message, ?array $response = null): void
    {
        $logAction = $this->syncLogAction();

        if ($logAction === null) {
            return;
        }

        $query = ProductSyncLog::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->where('action', $logAction);

        if ($this->uploadLogId) {
            $query->whereKey($this->uploadLogId);
        } else {
            $query->where('status', ProductSyncLog::STATUS_PENDING);
        }

        $log = $query->latest()->first();

        if (! $log) {
            $log = ProductSyncLog::record([
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $logAction,
                'status' => $success ? ProductSyncLog::STATUS_SUCCESS : ProductSyncLog::STATUS_FAILED,
            ]);
        }

        if (! $log) {
            return;
        }

        $this->uploadResultRecorded = true;

        if ($success) {
            $log->update([
                'status' => ProductSyncLog::STATUS_SUCCESS,
                'error_message' => null,
                'response' => $response,
            ]);

            return;
        }

        $structured = is_array($response) && isset($response['error']) && is_array($response['error'])
            ? $response['error']
            : UploadErrorPresenter::fromMessage($this->channelCodeResolved, (string) $message);

        $raw = $response;
        if (is_array($raw)) {
            unset($raw['error']);
        }

        $log->update([
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => $structured['reason'] ?? $message,
            'response' => [
                'error' => $structured,
                'original_message' => $message,
                'raw' => ! empty($raw) ? $raw : null,
            ],
        ]);
    }

    protected function recordSkipped(string $message): void
    {
        $logAction = $this->syncLogAction();

        if ($logAction === null) {
            return;
        }

        $query = ProductSyncLog::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->where('action', $logAction);

        if ($this->uploadLogId) {
            $query->whereKey($this->uploadLogId);
        } else {
            $query->where('status', ProductSyncLog::STATUS_PENDING);
        }

        $log = $query->latest()->first();

        if (! $log) {
            $log = ProductSyncLog::record([
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $logAction,
                'status' => ProductSyncLog::STATUS_SKIPPED,
            ]);
        }

        $this->uploadResultRecorded = true;
        $log->update([
            'status' => ProductSyncLog::STATUS_SKIPPED,
            'error_message' => null,
            'response' => [
                'outcome' => ProductSyncLog::STATUS_SKIPPED,
                'reason' => $message,
            ],
        ]);
    }

    private function syncLogAction(): ?string
    {
        return match ($this->action) {
            'push', 'update' => ProductSyncLog::ACTION_UPLOAD,
            'sync_price_stock', 'sync_stock' => ProductSyncLog::ACTION_SYNC_STOCK,
            'sync_price' => ProductSyncLog::ACTION_SYNC_PRICE,
            default => null,
        };
    }

    protected function handleFailure(string $channelCode): void
    {
        $failKey = $this->circuitFailureKey($channelCode);
        $threshold = config('channel.circuit_breaker_threshold', 10);

        $count = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $count, 300);

        if ($count >= $threshold) {
            $cooldownMinutes = config('channel.circuit_breaker_cooldown_minutes', 5);
            Cache::put($this->circuitBreakerKey($channelCode), true, now()->addMinutes($cooldownMinutes));
            Cache::forget($failKey);
            Log::error("CIRCUIT BREAKER OPENED for {$channelCode}/{$this->channelShopId} due to {$count} consecutive failures.");
        }
    }

    protected function resetFailureState(string $channelCode): void
    {
        Cache::forget($this->circuitFailureKey($channelCode));
        Cache::forget($this->circuitBreakerKey($channelCode));
    }

    protected function circuitFailureKey(string $channelCode): string
    {
        return "circuit_fail_count:{$channelCode}:{$this->channelShopId}";
    }

    protected function circuitBreakerKey(string $channelCode): string
    {
        return "circuit_breaker:{$channelCode}:{$this->channelShopId}";
    }

    protected function updateVariantMappings(ProductChannelMapping $mapping, Product $product, array $skus): void
    {
        foreach ($skus as $skuData) {
            if (empty($skuData['seller_sku'])) {
                continue;
            }

            $variant = $product->variants->where('sku', $skuData['seller_sku'])->first();
            if ($variant) {
                $attributes = [
                    'external_sku_id' => $skuData['id'] ?? null,
                    'channel_seller_sku' => $skuData['seller_sku'],
                ];

                $sale = $skuData['sales_attributes'][0] ?? null;
                if (is_array($sale)) {
                    $saleId = $sale['attribute_id'] ?? $sale['id'] ?? null;
                    $saleName = $sale['attribute_name'] ?? $sale['name'] ?? null;
                    if ($saleId !== null) {
                        $attributes['sales_attribute_id'] = (string) $saleId;
                    }
                    if ($saleName !== null) {
                        $attributes['sales_attribute_name'] = (string) $saleName;
                    }
                }

                $mapping->variantMappings()->updateOrCreate(
                    ['variant_id' => $variant->id],
                    $attributes
                );
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->isOutboxStockDelivery()) {
            $outbox = app(ChannelStockSyncOutboxService::class);
            $outbox->defer(
                $this->stockOutboxId,
                $this->stockOutboxVersion,
                $exception->getMessage(),
                $outbox->retryDelaySeconds($this->outboxAttemptCount()),
            );

            return;
        }

        $recorder = app(QueueFailureRecorder::class);
        $jobUuid = $this->job?->uuid();
        $hasOriginalFailure = $recorder->hasAttemptException($jobUuid);
        $message = $recorder->messageForJob($jobUuid, $exception);

        if (! $this->uploadResultRecorded) {
            $this->recordUploadResult(false, $message);
        }

        if (self::isStockAction($this->action) && $this->channelMappingId === null) {
            return;
        }

        $mappings = ProductChannelMapping::where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->when(
                self::isStockAction($this->action),
                fn ($query) => $query->whereKey($this->channelMappingId),
            )
            ->get();

        foreach ($mappings as $mapping) {
            if (! $hasOriginalFailure
                && $this->isGenericQueueFailure($exception)
                && $mapping->sync_status === ProductChannelMapping::STATUS_FAILED
                && filled($mapping->error_message)) {
                continue;
            }

            $mapping->markAsFailed($message);
        }
    }

    private function isGenericQueueFailure(\Throwable $exception): bool
    {
        if ($exception instanceof MaxAttemptsExceededException) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'maxattemptsexceeded')
            || str_contains($message, 'attempted too many times');
    }

    private function isOutboxStockDelivery(): bool
    {
        return self::isStockAction($this->action)
            && $this->stockOutboxId !== null
            && $this->stockOutboxVersion !== null;
    }

    private function outboxAttemptCount(): int
    {
        if (! $this->isOutboxStockDelivery()) {
            return 1;
        }

        return app(ChannelStockSyncOutboxService::class)->attemptCount($this->stockOutboxId);
    }
}
