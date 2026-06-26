<?php

namespace Modules\Inventory\Services;

use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Product\Models\ProductChannelMapping;

class MonitorStockService
{
    public function __construct(
        protected MonitorStockRepository $repository,
    ) {}

    /** @return array{search?:string,category_id?:string,location_id?:string} */
    public function filtersFrom(array $input): array
    {
        return array_filter([
            'search'      => $input['search'] ?? null,
            'category_id' => $input['category_id'] ?? null,
            'location_id' => $input['location_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function outOfStock(string $mode, array $filters, int $perPage = 10)
    {
        // hanya mode valid untuk tab Stok Kosong
        $mode = in_array($mode, ['habis', 'minus', 'dipesan'], true) ? $mode : 'habis';

        return $this->repository->paginateMode($mode, $filters, $perPage);
    }

    public function lowStock(array $filters, int $perPage = 10)
    {
        return $this->repository->paginateMode('menipis', $filters, $perPage);
    }

    public function onOrder(array $filters, int $perPage = 10)
    {
        return $this->repository->paginateMode('on-order', $filters, $perPage);
    }

    public function summary(array $filters): array
    {
        return $this->repository->summary($filters);
    }

    // ===================== Fase 3: Analitik penjualan (default ADR-203) =====================

    public function deadStock(array $filters, int $days = 90, int $perPage = 10)
    {
        return $this->repository->deadStock($filters, max(1, $days), $perPage);
    }

    public function fastMoving(array $filters, int $windowDays = 30, int $perPage = 10)
    {
        return $this->repository->fastMoving($filters, max(1, $windowDays), $perPage);
    }

    public function estimatedStockOut(array $filters, int $windowDays = 30, int $thresholdDays = 30, int $perPage = 10)
    {
        return $this->repository->estimatedStockOut($filters, max(1, $windowDays), max(1, $thresholdDays), $perPage);
    }

    // ===================== Fase 4: Gagal Sync =====================

    public function failedSync(int $perPage = 10)
    {
        return $this->repository->failedSync($perPage);
    }

    public function retrySync(string $mappingId): ProductChannelMapping
    {
        $mapping = ProductChannelMapping::findOrFail($mappingId);

        SyncProductToChannelJob::dispatch(
            $mapping->product_id,
            $mapping->channel_shop_id,
            'sync_price_stock'
        );

        $mapping->markAsSyncing();

        return $mapping->fresh();
    }

    public function retryBulkSync(array $ids): int
    {
        $mappings = ProductChannelMapping::whereIn('id', $ids)->failed()->get();

        foreach ($mappings as $mapping) {
            SyncProductToChannelJob::dispatch(
                $mapping->product_id,
                $mapping->channel_shop_id,
                'sync_price_stock'
            );
            $mapping->markAsSyncing();
        }

        return $mappings->count();
    }
}
