<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Models\ReservedStockItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Database\Eloquent\Collection;

class ReservedStockRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(ReservedStock::class)
            ->with(['location:id,location_name', 'items'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::partial('q', 'reserved_stock_no'),
                AllowedFilter::exact('is_active'),
            )
            ->allowedSorts('start_date', 'end_date', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?ReservedStock
    {
        return ReservedStock::with(['items.product:id,sku,product_id', 'items.bin:id,bin_final_code', 'location:id,location_name'])
            ->find($id);
    }

    public function findByIdForUpdate(string $id): ?ReservedStock
    {
        return ReservedStock::lockForUpdate()->find($id);
    }

    public function create(array $data): ReservedStock
    {
        return ReservedStock::create($data);
    }

    public function createItem(array $data): ReservedStockItem
    {
        return ReservedStockItem::create($data);
    }

    public function getExpired(): Collection
    {
        return ReservedStock::where('status', ReservedStock::STATUS_ACTIVE)
            ->where('end_date', '<', now())
            ->with('items')
            ->get();
    }

    public function deactivate(string $id): bool
    {
        return ReservedStock::where('id', $id)->update([
            'status' => ReservedStock::STATUS_EXPIRED,
            'is_active' => false,
        ]);
    }

    public function generateReservedStockNo(): string
    {
        $date = now()->format('Ymd');
        $prefix = "RSV-{$date}-";

        $last = ReservedStock::where('reserved_stock_no', 'like', "{$prefix}%")
            ->orderByDesc('reserved_stock_no')
            ->value('reserved_stock_no');

        if ($last) {
            $seq = (int) substr($last, -4) + 1;
        } else {
            $seq = 1;
        }

        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
