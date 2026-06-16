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

        // B5: stok bundle = derivasi komponen → bila stok varian ini berubah,
        // stok turunan semua bundle yang memakainya ikut berubah. Re-sync juga.
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

            if ($mapping->sync_status !== 'pending' && $mapping->sync_status !== 'deactivated') {
                SyncProductToChannelJob::dispatch(
                    $product->id,
                    $mapping->channel_shop_id,
                    'sync_price_stock'
                );
            }
        }
    }
}
