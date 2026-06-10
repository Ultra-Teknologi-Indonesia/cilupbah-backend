<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\StockRevaluation;
use Modules\Inventory\Models\StockRevaluationItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class StockRevaluationRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(StockRevaluation::class)
            ->with(['location:id,location_name,location_code'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('q', 'revaluation_no'),
            )
            ->allowedSorts('created_at', 'revaluation_no', 'approved_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?StockRevaluation
    {
        return StockRevaluation::with([
            'items.product:id,sku,product_id',
            'items.bin:id,bin_final_code',
            'location:id,location_name,location_code',
        ])->find($id);
    }

    public function findByIdForUpdate(string $id): ?StockRevaluation
    {
        return StockRevaluation::with('items')
            ->lockForUpdate()
            ->find($id);
    }

    public function create(array $data): StockRevaluation
    {
        return StockRevaluation::create($data);
    }

    public function createItem(array $data): StockRevaluationItem
    {
        return StockRevaluationItem::create($data);
    }

    public function generateRevaluationNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "RV-{$date}-";

        $last = StockRevaluation::where('revaluation_no', 'like', "{$prefix}%")
            ->orderByDesc('revaluation_no')
            ->value('revaluation_no');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
