<?php

namespace Modules\Purchase\Repositories;

use App\Support\WarehouseAccess;
use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchaseBillItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Str;

class PurchaseBillRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseBill::class)
            ->with(['contact:id,name,code', 'location:id,location_name', 'purchaseOrder:id,po_number'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('contact_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::scope('date_from', 'whereDateFrom'),
                AllowedFilter::scope('date_to', 'whereDateTo'),
            )
            ->allowedSearch('bill_number')
            ->allowedSorts('bill_number', 'bill_date', 'due_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?PurchaseBill
    {
        $query = PurchaseBill::with(['contact', 'location', 'purchaseOrder', 'items.variant.product:id,name', 'payments']);
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function findByIdForUpdate(string $id): ?PurchaseBill
    {
        $query = PurchaseBill::lockForUpdate();
        WarehouseAccess::apply($query, 'location_id');

        return $query->find($id);
    }

    public function create(array $data): PurchaseBill
    {
        return PurchaseBill::create($data);
    }

    public function createItem(array $data): PurchaseBillItem
    {
        return PurchaseBillItem::create($data);
    }

    public function update(PurchaseBill $bill, array $data): PurchaseBill
    {
        $bill->update($data);
        return $bill->fresh();
    }

    public function delete(PurchaseBill $bill): bool
    {
        return $bill->delete();
    }

    public function getUnpaid(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_PARTIAL])
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->with(['contact:id,name,code', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('contact_id'),
            )
            ->allowedSearch('bill_number')
            ->allowedSorts('bill_date', 'due_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function getOverdue(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_PARTIAL])
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->where('due_date', '<', now()->toDateString())
            ->with(['contact:id,name,code', 'location:id,location_name'])
            ->allowedSorts('due_date', 'total_amount', 'created_at')
            ->defaultSort('due_date');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function getForReturn(int $limit = 10)
    {
        $query = QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_PAID])
            ->with(['contact:id,name,code'])
            ->select('id', 'bill_number', 'contact_id', 'total_amount', 'bill_date')
            ->allowedFilters(
                AllowedFilter::exact('contact_id'),
            )
            ->allowedSearch('bill_number')
            ->defaultSort('-created_at');
        WarehouseAccess::apply($query, 'location_id');

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function generateBillNo(): string
    {
        $prefix = 'BILL-';

        $last = PurchaseBill::whereRaw("bill_number ~ '^BILL-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(bill_number FROM 6) AS BIGINT) DESC")
            ->value('bill_number');

        $seq = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($seq, 9, '0', STR_PAD_LEFT);
    }
}
