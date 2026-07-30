<?php

namespace Modules\Channel\Services;

use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Product\Models\ProductChannelMapping;

/**
 * Orkestrasi TRIGGER MANUAL sinkronisasi STOK-SAJA ke channel.
 *
 * Menyusun mapping produk↔toko yang layak lalu men-dispatch
 * SyncProductToChannelJob(..., 'sync_stock') per mapping. Guard yang dipakai
 * identik dengan SyncStockToChannelsJob (skip mapping 'deactivated' dan
 * listing yang seluruh variannya sync_enabled=false).
 */
class ManualStockSyncService
{
    /**
     * Antrekan sync stok untuk sekumpulan produk (mode single/bulk).
     *
     * @param  array<int, string>  $productIds
     * @param  string|null  $channelShopId  Bila diisi, hanya toko tsb; null = semua mapping aktif produk.
     * @return array{products:int, products_with_mappings:int, queued:int, skipped:int}
     */
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

    /**
     * Antrekan sync stok untuk SELURUH mapping (mode all), dipanggil dari
     * dalam job orchestrator agar tidak membebani request. Menggunakan
     * chunkById agar hemat memori untuk data besar.
     *
     * Filter opsional yang didukung:
     *  - channel_shop_id   : string
     *  - channel_shop_ids  : array<string>
     *  - product_ids       : array<string>
     *
     * @param  array<string, mixed>  $filters
     * @return int  Jumlah job sync stok yang diantrekan.
     */
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

    /**
     * Mapping aktif untuk satu produk (skip 'deactivated'), opsional dibatasi
     * ke satu toko.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ProductChannelMapping>
     */
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

    /**
     * True bila SELURUH varian pada listing ini sync_enabled=false — tiru guard
     * di SyncStockToChannelsJob::listingSyncFullyDisabled.
     */
    public function listingSyncFullyDisabled(ProductChannelMapping $mapping): bool
    {
        $variantMappings = $mapping->variantMappings()->get(['sync_enabled']);

        return $variantMappings->isNotEmpty()
            && $variantMappings->every(fn ($vm) => ! $vm->sync_enabled);
    }
}
