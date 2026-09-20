<?php

namespace Modules\Channel\Services;

use Modules\Channel\Jobs\ManualStockResyncAllJob;
use Modules\Channel\Support\ChannelVariantMappingResolver;
use Modules\Product\Models\ProductChannelMapping;

class ManualStockSyncService
{
    public function queueAll(array $filters = []): void
    {
        ManualStockResyncAllJob::dispatch($filters);
    }

    public function syncProducts(array $productIds, ?string $channelShopId = null): array
    {
        $productIds = array_values(array_unique($productIds));

        $queued = 0;
        $skipped = 0;
        $productsWithMappings = 0;

        foreach ($productIds as $productId) {
            $mappings = $this->resolveMappings($productId, $channelShopId);

            if ($mappings->isNotEmpty()) {
                $productsWithMappings++;
            }

            foreach ($mappings as $mapping) {
                if (blank($mapping->external_product_id)) {
                    $skipped++;

                    continue;
                }

                if ($this->listingSyncFullyDisabled($mapping)) {
                    $skipped++;

                    continue;
                }

                if (ChannelVariantMappingResolver::hasEnabledMappings($mapping)
                    && ChannelVariantMappingResolver::enabledForListing($mapping)->isEmpty()) {
                    $skipped++;

                    continue;
                }

                app(ChannelStockSyncOutboxService::class)->request($mapping, 'sync_stock', 'bulk');

                $queued++;
            }
        }

        return [
            'products' => count($productIds),
            'products_with_mappings' => $productsWithMappings,
            'queued' => $queued,
            'skipped' => $skipped,
        ];
    }

    public function dispatchAll(array $filters = []): int
    {
        $queued = 0;

        ProductChannelMapping::query()
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->when(
                ! empty($filters['channel_shop_id']),
                fn ($q) => $q->where('channel_shop_id', $filters['channel_shop_id'])
            )
            ->when(
                ! empty($filters['channel_shop_ids']),
                fn ($q) => $q->whereIn('channel_shop_id', $filters['channel_shop_ids'])
            )
            ->when(
                ! empty($filters['product_ids']),
                fn ($q) => $q->whereIn('product_id', $filters['product_ids'])
            )
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('variantMappings.variant')
            ->chunkById(500, function ($mappings) use (&$queued) {
                foreach ($mappings as $mapping) {
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

                    app(ChannelStockSyncOutboxService::class)->request($mapping, 'sync_stock', 'bulk');

                    $queued++;
                }
            });

        return $queued;
    }

    public function resolveMappings(string $productId, ?string $channelShopId = null)
    {
        return ProductChannelMapping::query()
            ->where('product_id', $productId)
            ->where('sync_status', '!=', ProductChannelMapping::STATUS_DEACTIVATED)
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('variantMappings.variant')
            ->when(
                $channelShopId !== null,
                fn ($q) => $q->where('channel_shop_id', $channelShopId)
            )
            ->get();
    }

    public function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->relationLoaded('variantMappings')
            ? $mapping->variantMappings
            : $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($vm) => ! $vm->sync_enabled);
    }
}
