<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\Product;
use Modules\Product\Models\ProductBundleItem;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Product\Models\ProductVariant;
use Modules\Product\Repositories\ProductRepository;

class SyncStockToChannelsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $variantId;

    public ?string $excludeChannelShopId;

    public int $uniqueFor = 300;

    public function __construct(string $variantId, ?string $excludeChannelShopId = null)
    {
        $this->variantId = $variantId;
        $this->excludeChannelShopId = $excludeChannelShopId;
        // Marketplace propagation is deliberately isolated from warehouse
        // inventory/picking jobs. It remains asynchronous and idempotent.
        $this->onConnection(config('queue.routing.channel_stock.connection', 'redis'))
            ->onQueue(config('queue.routing.channel_stock.queue', 'channel-stock'));
    }

    public function uniqueId(): string
    {
        return implode(':', [
            'variant-stock',
            $this->variantId,
            $this->excludeChannelShopId ?? '*',
        ]);
    }

    public function handle(ProductRepository $productRepository): void
    {
        $variant = ProductVariant::query()
            ->whereKey($this->variantId)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('product.channelMappings.variantMappings.variant')
            ->first();

        if (! $variant || ! $variant->product) {
            return;
        }

        $dispatched = [];
        $this->dispatchForProduct($variant->product, $dispatched);

        $bundleIds = $productRepository->bundleProductIdsUsingComponent($this->variantId);

        if ($bundleIds === []) {
            return;
        }

        Product::with('channelMappings.variantMappings.variant')
            ->whereIn('id', $bundleIds)
            ->where('is_active', true)
            ->get()
            ->each(fn (Product $bundle) => $this->dispatchForProduct($bundle, $dispatched));

        $siblingVariantIds = ProductBundleItem::whereIn('bundle_product_id', $bundleIds)
            ->where('component_variant_id', '!=', $this->variantId)
            ->pluck('component_variant_id')
            ->unique()
            ->all();

        if (! empty($siblingVariantIds)) {
            ProductVariant::with('product.channelMappings.variantMappings.variant')
                ->whereIn('id', $siblingVariantIds)
                ->where('is_active', true)
                ->whereHas('product', fn ($query) => $query->where('is_active', true))
                ->get()
                ->pluck('product')
                ->filter()
                ->unique('id')
                ->each(fn (Product $prod) => $this->dispatchForProduct($prod, $dispatched));
        }
    }

    private function dispatchForProduct(Product $product, array &$dispatched): void
    {
        if (! $product->is_active || $product->trashed()) {
            return;
        }

        foreach ($product->channelMappings as $mapping) {
            $dispatchKey = "{$product->id}:{$mapping->channel_shop_id}";

            if (isset($dispatched[$dispatchKey])) {
                continue;
            }

            if ($this->excludeChannelShopId !== null && $mapping->channel_shop_id === $this->excludeChannelShopId) {
                continue;
            }

            if ($mapping->sync_status === 'deactivated') {
                continue;
            }

            if (blank($mapping->external_product_id)) {
                continue;
            }

            if ($this->listingSyncFullyDisabled($mapping)) {
                continue;
            }

            if (ChannelVariantMappingResolver::hasEnabledMappings($mapping)
                && ChannelVariantMappingResolver::enabledForListing($mapping)->isEmpty()) {
                continue;
            }

            $dispatched[$dispatchKey] = true;

            SyncProductToChannelJob::dispatch($product->id, $mapping->channel_shop_id, 'sync_stock');
        }
    }

    private function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->relationLoaded('variantMappings')
            ? $mapping->variantMappings
            : $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($vm) => ! $vm->sync_enabled);
    }
}
