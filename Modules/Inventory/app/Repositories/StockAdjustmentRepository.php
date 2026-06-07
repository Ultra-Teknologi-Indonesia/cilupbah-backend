<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\StockAdjustment;
use Modules\Inventory\Models\StockAdjustmentItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class StockAdjustmentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(StockAdjustment::class)
            ->with(['location:id,location_name', 'items'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('q', 'adjustment_no'),
                AllowedFilter::exact('is_beginning_balance'),
            )
            ->allowedSorts('transaction_date', 'created_at', 'adjustment_no')
            ->defaultSort('-transaction_date')
            ->paginate($limit);
    }

    public function findById(string $id): ?StockAdjustment
    {
        return StockAdjustment::with(['items.product:id,sku,product_id', 'items.bin:id,bin_final_code', 'location:id,location_name'])
            ->find($id);
    }

    public function findByIdForUpdate(string $id): ?StockAdjustment
    {
        return StockAdjustment::lockForUpdate()->find($id);
    }

    public function create(array $data): StockAdjustment
    {
        return StockAdjustment::create($data);
    }

    public function createItem(array $data): StockAdjustmentItem
    {
        return StockAdjustmentItem::create($data);
    }

    public function updateStatus(string $id, string $status, array $extra = []): bool
    {
        return StockAdjustment::where('id', $id)->update(array_merge(['status' => $status], $extra));
    }

    public function delete(string $id): bool
    {
        return StockAdjustment::where('id', $id)->delete();
    }

    public function generateAdjustmentNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "ADJ-{$date}-";

        $last = StockAdjustment::where('adjustment_no', 'like', "{$prefix}%")
            ->orderByDesc('adjustment_no')
            ->value('adjustment_no');

        if ($last) {
            $seq = (int) substr($last, -4) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
