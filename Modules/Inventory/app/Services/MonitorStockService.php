<?php

namespace Modules\Inventory\Services;

use Modules\Channel\Jobs\SyncProductToChannelJob;
use Modules\Inventory\Repositories\MonitorStockRepository;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Warehouse\Models\Location;

class MonitorStockService
{
    public function __construct(
        protected MonitorStockRepository $repository,
    ) {}

    public function filtersFrom(array $input): array
    {
        return array_filter([
            'search' => $input['search'] ?? null,
            'category_id' => $input['category_id'] ?? null,
            'location_id' => $input['location_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function outOfStock(string $mode, array $filters, int $perPage = 20)
    {

        $mode = $this->normalizeOutOfStockMode($mode);

        return $this->repository->paginateMode(
            $mode,
            $this->filtersForMode($mode, $filters),
            $perPage,
        );
    }

    public function lowStock(array $filters, int $perPage = 20)
    {
        return $this->repository->paginateMode('menipis', $filters, $perPage);
    }

    public function onOrder(array $filters, int $perPage = 20)
    {
        return $this->repository->paginateMode('on-order', $filters, $perPage);
    }

    public function summary(array $filters, ?string $mode = null): array
    {
        return $this->repository->summary(
            $this->filtersForMode($mode, $filters),
        );
    }

    public function prepareExportParams(array $params): array
    {
        if (($params['tab'] ?? null) !== 'stok-kosong') {
            return $params;
        }

        $mode = $this->normalizeOutOfStockMode($params['mode'] ?? 'habis');

        return [
            ...$this->filtersForMode($mode, $params),
            'mode' => $mode,
        ];
    }

    public function filtersForMode(?string $mode, array $filters): array
    {
        return $this->applyDefaultSmallWarehouseForMinus($mode, $filters);
    }

    private function normalizeOutOfStockMode(string $mode): string
    {
        return in_array($mode, ['habis', 'minus', 'dipesan'], true) ? $mode : 'habis';
    }

    private function applyDefaultSmallWarehouseForMinus(?string $mode, array $filters): array
    {
        if ($mode !== 'minus' || ! empty($filters['location_id'])) {
            return $filters;
        }

        $smallWarehouseId = Location::getOfficialSmallWarehouseId();

        if (! $smallWarehouseId) {
            return $filters;
        }

        $filters['location_id'] = $smallWarehouseId;

        return $filters;
    }

    public function deadStock(array $filters, int $days = 90, int $perPage = 20)
    {
        return $this->repository->deadStock($filters, max(1, $days), $perPage);
    }

    public function fastMoving(array $filters, int $windowDays = 30, int $perPage = 20)
    {
        return $this->repository->fastMoving($filters, max(1, $windowDays), $perPage);
    }

    public function estimatedStockOut(array $filters, int $windowDays = 30, int $thresholdDays = 30, int $perPage = 20)
    {
        return $this->repository->estimatedStockOut($filters, max(1, $windowDays), max(1, $thresholdDays), $perPage);
    }

    public function failedSync(int $perPage = 20)
    {
        return $this->repository->failedSync($perPage);
    }

    public function retrySync(string $mappingId): ProductChannelMapping
    {
        $mapping = ProductChannelMapping::findOrFail($mappingId);

        SyncProductToChannelJob::dispatch(
            $mapping->product_id,
            $mapping->channel_shop_id,
            'sync_stock'
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
                'sync_stock'
            );
            $mapping->markAsSyncing();
        }

        return $mappings->count();
    }
}
