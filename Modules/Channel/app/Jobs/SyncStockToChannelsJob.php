<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;

class SyncStockToChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $variantId;

    public ?string $excludeChannelShopId;

    public function __construct(string $variantId, ?string $excludeChannelShopId = null)
    {
        $this->variantId = $variantId;
        $this->excludeChannelShopId = $excludeChannelShopId;
        $this->onQueue(config('queue.names.channel_sync'));
    }

    public function handle(ProductRepository $productRepository): void
    {
        $variant = ProductVariant::with('product.channelMappings')->find($this->variantId);

        if (!$variant || !$variant->product) {
            return;
        }

        $this->dispatchForProduct($variant->product);

        $bundleIds = $productRepository->bundleProductIdsUsingComponent($this->variantId);

        if ($bundleIds === []) {
            return;
        }

        Product::with('channelMappings')
            ->whereIn('id', $bundleIds)
            ->get()
            ->each(fn (Product $bundle) => $this->dispatchForProduct($bundle));
    }

    private function dispatchForProduct(Product $product): void
    {
        foreach ($product->channelMappings as $mapping) {
            if ($this->excludeChannelShopId !== null && $mapping->channel_shop_id === $this->excludeChannelShopId) {
                continue;
            }

            // Skip hanya mapping yang sengaja di-deactivate user.
            // Mapping `pending` (belum listed di marketplace) JUGA dipush — di
            // SyncProductToChannelJob, action 'sync_price_stock' tanpa external_id
            // akan fallback ke pushProduct() yang membuat listing baru. Setelah
            // sukses, status mapping otomatis flip ke synced/in_review.
            if ($mapping->sync_status === 'deactivated') {
                continue;
            }

            SyncProductToChannelJob::dispatch(
                $product->id,
                $mapping->channel_shop_id,
                'sync_price_stock'
            );
        }
    }
}
