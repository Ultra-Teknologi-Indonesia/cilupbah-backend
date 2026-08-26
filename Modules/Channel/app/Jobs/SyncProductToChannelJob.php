<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Adapters\AdapterFactory;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Services\ChannelListingValidator;
use Modules\Channel\Services\LazadaAuthService;
use Modules\Channel\Services\ShopeeAuthService;
use Modules\Channel\Services\TikTokAuthService;
use Modules\Product\Jobs\RecomputeProductChannelValidationJob;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class SyncProductToChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;
    public array $backoff = [30, 120, 300];

    public string $productId;
    public string $channelShopId;
    public string $action;
    public ?array $attributeMapping;
    public ?string $draftId;

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

    public function __construct(string $productId, string $channelShopId, string $action, ?array $attributeMapping = null, ?string $draftId = null)
    {
        $this->productId = $productId;
        $this->channelShopId = $channelShopId;
        $this->action = $action;
        $this->attributeMapping = $attributeMapping;
        $this->draftId = $draftId;

        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function middleware(): array
    {
        return [
            new RateLimited('channel_api'),
            (new WithoutOverlapping("product_sync:{$this->productId}:{$this->channelShopId}"))->releaseAfter(60),
        ];
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHour();
    }

    public function handle(AdapterFactory $factory): void
    {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        $product = Product::with(['variants.channelMappings.channelMapping'])->find($this->productId);
        $shop = ChannelShop::with('channel')->find($this->channelShopId);

        if (!$product || !$shop) {
            Log::warning("SyncProductToChannelJob skipped: Product or Shop not found.", [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId
            ]);
            return;
        }

        if (! $this->shopAllowsAction($shop)) {
            Log::info("SyncProductToChannelJob skipped: sinkronisasi untuk toko ini dimatikan.", [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId,
                'action' => $this->action,
                'axis' => self::isStockAction($this->action) ? 'stok' : 'katalog',
                'is_shadow_mode' => (bool) $shop->is_shadow_mode,
            ]);
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

        $circuitKey = "circuit_breaker:{$channelCode}";
        if (Cache::has($circuitKey)) {
            Log::warning("Circuit breaker is open for {$channelCode}. Re-queuing job.", [
                'product_id' => $this->productId
            ]);
            $this->release(300);
            return;
        }

        $mapping = ProductChannelMapping::firstOrCreate([
            'product_id' => $this->productId,
            'channel_shop_id' => $this->channelShopId,
        ]);

        if ($this->action === 'delete' && !$mapping->external_product_id) {
            $mapping->delete();
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
                        $result = $adapter->syncPriceAndStock($product, $shop, $externalId);
                    } else {
                        $result = $adapter->pushProduct($product, $shop);
                    }
                    break;
                case 'sync_stock':
                    if ($externalId) {
                        $result = $adapter->syncStock($product, $shop, $externalId);
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

                if (!empty($result['skus'])) {
                    $this->updateVariantMappings($mapping, $product, $result['skus']);
                }

                if ($this->action === 'delete') {
                    $mapping->delete();
                }

                if ($this->draftId && in_array($this->action, ['push', 'update'], true)) {
                    try {
                        \Modules\Product\Models\ProductChannelDraft::whereKey($this->draftId)->delete();
                    } catch (\Throwable $e) {
                        Log::warning('Gagal menghapus draft setelah upload sukses: ' . $e->getMessage(), [
                            'draft_id' => $this->draftId,
                        ]);
                    }
                }

                $this->refreshChannelValidation();
            } else {
                $message = $result['message'] ?? 'Gagal mengeksekusi aksi';
                $this->lastActionableFailure = $message;

                if (empty($externalId) && ! empty($result['external_product_id'])) {
                    $mapping->update(['external_product_id' => (string) $result['external_product_id']]);
                }

                $mapping->markAsFailed($message);
                $this->recordUploadResult(false, $message, $result);
                $this->handleFailure($channelCode);

                throw new \Exception($message);
            }

        } catch (\Exception $e) {
            $this->lastActionableFailure = $e->getMessage();
            $mapping->markAsFailed($e->getMessage());
            if (! $this->uploadResultRecorded) {
                $this->recordUploadResult(false, $e->getMessage());
            }
            $this->refreshChannelValidation();
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
            Log::info("Token refreshed before sync", ['shop_id' => $shop->shop_id, 'channel' => $channelCode]);

            return $shop->fresh();
        } catch (\Throwable $e) {
            Log::warning("Token refresh gagal sebelum sync, lanjut dengan token lama", [
                'shop_id' => $shop->shop_id,
                'channel' => $channelCode,
                'error' => $e->getMessage(),
            ]);

            return $shop;
        }
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

        $log = ProductSyncLog::query()
            ->where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->where('action', ProductSyncLog::ACTION_UPLOAD)
            ->latest()
            ->first();

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
            ?? \Modules\Channel\Support\UploadErrorPresenter::fromMessage($this->channelCodeResolved, (string) $message);

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
        $failKey = "circuit_fail_count:{$channelCode}";
        $threshold = config('channel.circuit_breaker_threshold', 10);

        $count = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $count, 300);

        if ($count >= $threshold) {
            $cooldownMinutes = config('channel.circuit_breaker_cooldown_minutes', 5);
            Cache::put("circuit_breaker:{$channelCode}", true, now()->addMinutes($cooldownMinutes));
            Cache::forget($failKey);
            Log::error("CIRCUIT BREAKER OPENED for {$channelCode} due to {$count} consecutive failures.");
        }
    }

    protected function updateVariantMappings(ProductChannelMapping $mapping, Product $product, array $skus): void
    {
        foreach ($skus as $skuData) {
            if (empty($skuData['seller_sku'])) continue;

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
        $mappings = ProductChannelMapping::where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
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
