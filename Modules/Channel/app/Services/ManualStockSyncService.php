<?php

namespace Modules\Channel\Services;

use Modules\Channel\Jobs\ManualStockResyncAllJob;
use Modules\Channel\Jobs\SyncProductToChannelJob;
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
                if ($this->listingSyncFullyDisabled($mapping)) {
                    $skipped++;
                    continue;
                }

                SyncProductToChannelJob::dispatch(
                    $mapping->product_id,
                    $mapping->channel_shop_id,
                    'sync_stock'
                );

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
                !empty($filters['channel_shop_id']),
                fn ($q) => $q->where('channel_shop_id', $filters['channel_shop_id'])
            )
            ->when(
                !empty($filters['channel_shop_ids']),
                fn ($q) => $q->whereIn('channel_shop_id', $filters['channel_shop_ids'])
            )
            ->when(
                !empty($filters['product_ids']),
                fn ($q) => $q->whereIn('product_id', $filters['product_ids'])
            )
            ->chunkById(500, function ($mappings) use (&$queued) {
                foreach ($mappings as $mapping) {
                    if ($this->listingSyncFullyDisabled($mapping)) {
                        continue;
                    }

                    SyncProductToChannelJob::dispatch(
                        $mapping->product_id,
                        $mapping->channel_shop_id,
                        'sync_stock'
                    );

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
            ->when(
                $channelShopId !== null,
                fn ($q) => $q->where('channel_shop_id', $channelShopId)
            )
            ->get();
    }

    public function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($vm) => ! $vm->sync_enabled);
    }
}
