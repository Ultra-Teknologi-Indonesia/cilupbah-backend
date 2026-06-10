<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseBill;
use Modules\Purchase\Models\PurchaseBillItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;
use Illuminate\Support\Str;

class PurchaseBillRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseBill::class)
            ->with(['supplier:id,name,code', 'location:id,location_name', 'purchaseOrder:id,po_number'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::custom('search', new FuzzyFilter('bill_number'))
            )
            ->allowedSorts('bill_number', 'bill_date', 'due_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseBill
    {
        return PurchaseBill::with(['supplier', 'location', 'purchaseOrder', 'items.product:id,name,sku', 'payments'])
            ->find($id);
    }

    public function findByIdForUpdate(string $id): ?PurchaseBill
    {
        return PurchaseBill::lockForUpdate()->find($id);
    }

    public function create(array $data): PurchaseBill
    {
        return PurchaseBill::create($data);
    }

    public function createItem(array $data): PurchaseBillItem
    {
        return PurchaseBillItem::create($data);
    }

    public function delete(PurchaseBill $bill): bool
    {
        return $bill->delete();
    }

    public function getUnpaid(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_DRAFT])
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->with(['supplier:id,name,code', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::custom('search', new FuzzyFilter('bill_number'))
            )
            ->allowedSorts('bill_date', 'due_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function getOverdue(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_DRAFT])
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->where('due_date', '<', now()->toDateString())
            ->with(['supplier:id,name,code', 'location:id,location_name'])
            ->allowedSorts('due_date', 'total_amount', 'created_at')
            ->defaultSort('due_date')
            ->paginate($limit);
    }

    public function getForReturn(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseBill::class)
            ->whereIn('status', [PurchaseBill::STATUS_OPEN, PurchaseBill::STATUS_PAID])
            ->with(['supplier:id,name,code'])
            ->select('id', 'bill_number', 'supplier_id', 'total_amount', 'bill_date')
            ->allowedFilters(
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::custom('search', new FuzzyFilter('bill_number'))
            )
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function generateBillNo(): string
    {
        $prefix = 'BILL-' . now()->format('Ymd') . '-';
        $last = PurchaseBill::where('bill_number', 'like', $prefix . '%')
            ->orderByDesc('bill_number')
            ->value('bill_number');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
