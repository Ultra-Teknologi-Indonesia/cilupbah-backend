<?php

namespace Modules\Inventory\Repositories;

use App\Support\BusinessDateRange;
use App\Support\WarehouseAccess;
use Illuminate\Database\Eloquent\Collection;
use Modules\Inventory\Models\ReservedStock;
use Modules\Inventory\Models\ReservedStockItem;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ReservedStockRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(ReservedStock::class)
            ->with(['location:id,location_name', 'items'])
            ->allowedSearch('reserved_stock_no')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('is_active'),
                AllowedFilter::callback('date_from', fn ($query, $value) => $query->where('start_date', '>=', BusinessDateRange::start((string) $value))),
                AllowedFilter::callback('date_to', fn ($query, $value) => $query->where('start_date', '<', BusinessDateRange::endExclusive((string) $value))),
            )
            ->allowedSorts('start_date', 'end_date', 'created_at', 'reserved_stock_no', 'status')
            ->defaultSort('-created_at');

        WarehouseAccess::apply($query, 'location_id');

        return $query
            ->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }

    public function findById(string $id): ?ReservedStock
    {
        $query = ReservedStock::with(['items.product:id,sku,product_id', 'items.bin:id,bin_final_code', 'location:id,location_name']);
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function findByIdForUpdate(string $id): ?ReservedStock
    {
        $query = ReservedStock::lockForUpdate();
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
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
        $query = ReservedStock::where('status', ReservedStock::STATUS_ACTIVE)
            ->where('end_date', '<', now())
            ->with('items');

        WarehouseAccess::apply($query, 'location_id');

        return $query->get();
    }

    public function deactivate(string $id): bool
    {
        $query = ReservedStock::where('id', $id);
        WarehouseAccess::apply($query, 'location_id');

        return $query->update([
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

        return $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
