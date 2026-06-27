<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseReturn;
use Modules\Purchase\Models\PurchaseReturnItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;
use Illuminate\Support\Str;

class PurchaseReturnRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseReturn::class)
            ->with(['supplier:id,name,code', 'location:id,location_name', 'purchaseOrder:id,po_number'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('supplier_id'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::callback('date_from', fn($query, $value) => $query->where('return_date', '>=', $value)),
                AllowedFilter::callback('date_to', fn($query, $value) => $query->where('return_date', '<=', $value)),
                AllowedFilter::custom('search', new FuzzyFilter('return_number'))
            )
            ->allowedSorts('return_number', 'return_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseReturn
    {
        return PurchaseReturn::with(['supplier', 'location', 'purchaseOrder', 'items.variant.product:id,name'])
            ->find($id);
    }

    public function create(array $data): PurchaseReturn
    {
        return PurchaseReturn::create($data);
    }

    public function createItem(array $data): PurchaseReturnItem
    {
        return PurchaseReturnItem::create($data);
    }

    public function delete(PurchaseReturn $return): bool
    {
        return $return->delete();
    }

    public function getUnpaid(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseReturn::class)
            ->where('status', PurchaseReturn::STATUS_COMPLETED)
            ->whereDoesntHave('settlement', function ($q) {
                $q->where('status', 'COMPLETED');
            })
            ->with(['supplier:id,name,code', 'location:id,location_name'])
            ->allowedSorts('return_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function generateReturnNo(): string
    {
        $prefix = 'PR-' . now()->format('Ymd') . '-';
        $last = PurchaseReturn::where('return_number', 'like', $prefix . '%')
            ->orderByDesc('return_number')
            ->value('return_number');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
