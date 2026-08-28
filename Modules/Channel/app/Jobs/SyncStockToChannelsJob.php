<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductChannelMapping;
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
        $this->onQueue(config('queue.names.channel_stock'));
    }

    public function handle(ProductRepository $productRepository): void
    {
        $variant = ProductVariant::with('product.channelMappings')->find($this->variantId);

        if (! $variant || ! $variant->product) {
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

        $siblingVariantIds = ProductBundleItem::whereIn('bundle_product_id', $bundleIds)
            ->where('component_variant_id', '!=', $this->variantId)
            ->pluck('component_variant_id')
            ->unique()
            ->all();

        if (! empty($siblingVariantIds)) {
            ProductVariant::with('product.channelMappings')
                ->whereIn('id', $siblingVariantIds)
                ->get()
                ->pluck('product')
                ->filter()
                ->unique('id')
                ->each(fn (Product $prod) => $this->dispatchForProduct($prod));
        }
    }

    private function dispatchForProduct(Product $product): void
    {
        foreach ($product->channelMappings as $mapping) {
            if ($this->excludeChannelShopId !== null && $mapping->channel_shop_id === $this->excludeChannelShopId) {
                continue;
            }

            if ($mapping->sync_status === 'deactivated') {
                continue;
            }

            if ($this->listingSyncFullyDisabled($mapping)) {
                continue;
            }

            SyncProductToChannelJob::dispatch(
                $product->id,
                $mapping->channel_shop_id,
                'sync_price_stock'
            );
        }
    }

    private function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($vm) => ! $vm->sync_enabled);
    }
}
