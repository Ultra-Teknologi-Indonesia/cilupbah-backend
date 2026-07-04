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
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('q', 'adjustment_no'),
                AllowedFilter::exact('is_beginning_balance'),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->whereDate('transaction_date', '>=', $value)),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->whereDate('transaction_date', '<=', $value)),
            )
            ->allowedSorts('transaction_date', 'created_at', 'adjustment_no')
            ->defaultSort('-transaction_date')
            ->paginate($limit);
    }

    public function findById(string $id): ?StockAdjustment
    {
        return StockAdjustment::with(['location:id,location_name'])
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

    public function delete(string $id): void
    {
        StockAdjustment::where('id', $id)->delete();
    }

    public function getItemsPaginated(string $adjustmentId, int $limit = 10)
    {
        return QueryBuilder::for(StockAdjustmentItem::class)
            ->where('stock_adjustment_id', $adjustmentId)
            ->with([
                'product:id,sku,product_id',
                'product.product:id,name',
                'product.media',
                'product.product.media',
                'bin:id,bin_final_code'
            ])
            ->allowedSearch('notes')
            ->allowedSorts('created_at')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function generateAdjustmentNo(): string
    {
        $prefix = 'ADJ-';

        $last = StockAdjustment::whereRaw("adjustment_no ~ '^ADJ-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(adjustment_no FROM 5) AS INTEGER) DESC")
            ->value('adjustment_no');

        $seq = $last ? ((int) substr($last, strlen($prefix)) + 1) : 1;

        return $prefix . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
    }
}
