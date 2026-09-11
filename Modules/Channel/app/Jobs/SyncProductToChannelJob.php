<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\TikTokAuthService;
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

    protected string $channelCodeResolved = '';

    protected bool $uploadResultRecorded = false;

    protected ?string $lastActionableFailure = null;

    private const STOCK_ACTIONS = ['sync_price_stock', 'sync_stock'];

    public static function isStockAction(string $action): bool
    {
        return in_array($action, self::STOCK_ACTIONS, true);
    }

    private function shopAllowsAction(ChannelShop $shop): bool
    {
        return self::isStockAction($this->action)
            ? (bool) $shop->stock_push_enabled
            : (bool) $shop->catalog_push_enabled;
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
    ) {
        $this->productId = $productId;
        $this->channelShopId = $channelShopId;
        $this->action = $action;
        $this->attributeMapping = $attributeMapping;
        $this->draftId = $draftId;
        $this->uploadLogId = $uploadLogId;
        $this->queueTier = $queueTier;
        $this->channelMappingId = $channelMappingId;

        $routing = self::isStockAction($action)
            ? config(
                $queueTier === 'bulk'
                    ? 'queue.routing.stock_default'
                    : 'queue.routing.stock_critical',
                [
                    'connection' => 'redis',
                    'queue' => $queueTier === 'bulk' ? 'stock-default' : 'stock-critical',
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
        $listingScope = self::isStockAction($this->action)
            ? ($this->channelMappingId ?: 'all-listings')
            : 'catalog';

        return [
            (new RateLimited('channel_api'))->releaseAfter(5),
            (new WithoutOverlapping("product_sync:{$this->productId}:{$this->channelShopId}:{$listingScope}"))->releaseAfter(60),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function handle(AdapterFactory $factory): void
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            $this->recordUploadResult(false, 'Sinkronisasi channel sedang dinonaktifkan.');

            return;
        }

        $product = self::isStockAction($this->action)
            ? Product::find($this->productId)
            : Product::with(['variants.channelMappings.channelMapping'])->find($this->productId);
        $shop = ChannelShop::with('channel')->find($this->channelShopId);

        if (! $product || ! $shop) {
            $this->recordUploadResult(false, 'Produk atau toko tidak ditemukan saat job upload diproses.');

            Log::warning('SyncProductToChannelJob skipped: Product or Shop not found.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
            ]);

            return;
        }

        if (! $this->shopAllowsAction($shop)) {
            $message = self::isStockAction($this->action)
                ? 'Sinkronisasi stok untuk toko ini sedang dinonaktifkan.'
                : 'Upload katalog untuk toko ini sedang dinonaktifkan.';

            $this->recordUploadResult(false, $message);

            Log::info('SyncProductToChannelJob skipped: sinkronisasi untuk toko ini dimatikan.', [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $this->action,
                'axis' => self::isStockAction($this->action) ? 'stok' : 'katalog',
                'is_shadow_mode' => (bool) $shop->is_shadow_mode,
            ]);

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

                $this->resetFailureState($channelCode);
            } else {
                $message = $result['message'] ?? 'Gagal mengeksekusi aksi';
                $this->lastActionableFailure = $message;

                if (empty($externalId) && ! empty($result['external_product_id'])) {
                    $mapping->update(['external_product_id' => (string) $result['external_product_id']]);
                }

                $mapping->markAsFailed($message);
                $this->recordUploadResult(false, $message, $result);

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
            ->whereHas('variantMappings', fn ($query) => $query->where('sync_enabled', true))
            ->select(['id', 'product_id', 'channel_shop_id'])
            ->lazyById(100)
            ->each(function (ProductChannelMapping $mapping) use (&$dispatched): void {
                self::dispatch(
                    (string) $mapping->product_id,
                    (string) $mapping->channel_shop_id,
                    $this->action,
                    null,
                    null,
                    null,
                    $this->queueTier,
                    (string) $mapping->id,
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
        if (! in_array($this->action, ['push', 'update'], true)) {
            return;
        }

        $query = ProductSyncLog::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->where('action', ProductSyncLog::ACTION_UPLOAD);

        if ($this->uploadLogId) {
            $query->whereKey($this->uploadLogId);
        } else {
            $query->where('status', ProductSyncLog::STATUS_PENDING);
        }

        $log = $query->latest()->first();

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

        $structured = $response['error']
            ?? UploadErrorPresenter::fromMessage($this->channelCodeResolved, (string) $message);

        $raw = $response;
        if (is_array($raw)) {
            unset($raw['error']);
        }

        $log->update([
            'status' => ProductSyncLog::STATUS_FAILED,
            'error_message' => $structured['reason'] ?? $message,
            'response' => [
                'error' => $structured,
                'raw' => ! empty($raw) ? $raw : null,
            ],
        ]);
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
        if (! $this->uploadResultRecorded) {
            $this->recordUploadResult(
                false,
                'Sinkronisasi ke channel gagal setelah beberapa percobaan. Silakan coba lagi.'
            );
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

            if ($this->lastActionableFailure !== null
                && $mapping->sync_status === ProductChannelMapping::STATUS_FAILED
                && filled($mapping->error_message)) {
                continue;
            }

            $mapping->markAsFailed(
                'Sinkronisasi ke channel gagal setelah beberapa percobaan. Silakan coba lagi.'
            );
        }
    }
}
