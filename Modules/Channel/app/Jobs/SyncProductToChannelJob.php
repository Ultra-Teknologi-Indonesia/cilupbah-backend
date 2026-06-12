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
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductSyncLog;

class SyncProductToChannelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300]; 

    public string $productId;
    public string $channelShopId;
    public string $action;

    /**
     * Create a new job instance.
     *
     * @param string $productId
     * @param string $channelShopId
     * @param string $action 'push', 'update', 'delete', 'activate', 'deactivate', 'sync_price_stock'
     */
    public function __construct(string $productId, string $channelShopId, string $action)
    {
        $this->productId = $productId;
        $this->channelShopId = $channelShopId;
        $this->action = $action;

        $this->onQueue(config('queue.names.channel_sync'));
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('channel_api_' . $this->channelShopId),
            (new WithoutOverlapping("product_sync:{$this->productId}:{$this->channelShopId}"))->releaseAfter(60),
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(AdapterFactory $factory): void
    {
        $product = Product::with(['variants.channelMappings.channelMapping'])->find($this->productId);
        $shop = ChannelShop::with('channel')->find($this->channelShopId);

        if (!$product || !$shop) {
            Log::warning("SyncProductToChannelJob skipped: Product or Shop not found.", [
                'product_id' => $this->productId,
                'channel_shop_id' => $this->channelShopId
            ]);
            return;
        }

        foreach ($product->variants as $variant) {
            $mapping = $variant->channelMappings->first(function ($map) use ($shop) {
                return $map->channelMapping && $map->channelMapping->channel_shop_id === $shop->id;
            });

            if ($mapping && $mapping->override_price !== null) {
                $variant->sell_price = $mapping->override_price;
            }
        }

        $channelCode = $shop->channel->code ?? 'tiktok';
        
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
            $mapping->markAsFailed("Adapter not found for channel {$channelCode}");
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
                        $result = $adapter->pushProduct($product, $shop);
                    }
                    break;
                case 'update':
                    if ($externalId) {
                        $result = $adapter->updateProduct($product, $shop, $externalId);
                    } else {
                        $result = $adapter->pushProduct($product, $shop);
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
            }

            if ($result['success']) {
                $newExternalId = $result['external_product_id'] ?? $externalId;
                $mapping->markAsSynced($newExternalId);
                $this->recordUploadResult(true, null, $result);

                if (!empty($result['skus'])) {
                    $this->updateVariantMappings($mapping, $product, $result['skus']);
                }

                if ($this->action === 'delete') {
                    $mapping->delete();
                }
            } else {
                $message = $result['message'] ?? 'Gagal mengeksekusi aksi';
                $mapping->markAsFailed($message);
                $this->recordUploadResult(false, $message, $result);
                $this->handleFailure($channelCode);

                throw new \Exception($message);
            }

        } catch (\Exception $e) {
            $mapping->markAsFailed($e->getMessage());
            $this->recordUploadResult(false, $e->getMessage());
            $this->handleFailure($channelCode);

            throw $e;
        }
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

        $log->update([
            'status' => $success ? ProductSyncLog::STATUS_SUCCESS : ProductSyncLog::STATUS_FAILED,
            'error_message' => $success ? null : $message,
            'response' => $response,
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
                $mapping->variantMappings()->updateOrCreate(
                    ['variant_id' => $variant->id],
                    [
                        'external_sku_id' => $skuData['id'],
                    ]
                );
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $mapping = ProductChannelMapping::where('product_id', $this->productId)
            ->where('channel_shop_id', $this->channelShopId)
            ->first();
            
        if ($mapping) {
            $mapping->markAsFailed($exception->getMessage());
        }
    }
}
