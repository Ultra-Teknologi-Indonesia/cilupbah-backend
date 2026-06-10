<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchaseReturnSettlement;
use Modules\Purchase\Models\PurchaseReturnSettlementBill;
use Modules\Purchase\Models\PurchaseReturnSettlementRefund;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use App\Filters\FuzzyFilter;
use Illuminate\Support\Str;

class PurchaseReturnSettlementRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseReturnSettlement::class)
            ->with(['purchaseReturn:id,return_number,supplier_id', 'purchaseReturn.supplier:id,name,code'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('return_id'),
                AllowedFilter::custom('search', new FuzzyFilter('settlement_number'))
            )
            ->allowedSorts('settlement_number', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?PurchaseReturnSettlement
    {
        return PurchaseReturnSettlement::with(['purchaseReturn.supplier', 'bills.bill', 'refunds'])
            ->find($id);
    }

    public function create(array $data): PurchaseReturnSettlement
    {
        return PurchaseReturnSettlement::create($data);
    }

    public function delete(PurchaseReturnSettlement $settlement): bool
    {
        return $settlement->delete();
    }

    public function getBills(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseReturnSettlementBill::class)
            ->with(['settlement:id,settlement_number,return_id', 'bill:id,bill_number'])
            ->allowedFilters(AllowedFilter::exact('settlement_id'))
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findBillById(string $id): ?PurchaseReturnSettlementBill
    {
        return PurchaseReturnSettlementBill::with(['settlement', 'bill'])->find($id);
    }

    public function createBill(array $data): PurchaseReturnSettlementBill
    {
        return PurchaseReturnSettlementBill::create($data);
    }

    public function getRefunds(int $limit = 10)
    {
        return QueryBuilder::for(PurchaseReturnSettlementRefund::class)
            ->with(['settlement:id,settlement_number,return_id'])
            ->allowedFilters(AllowedFilter::exact('settlement_id'))
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findRefundById(string $id): ?PurchaseReturnSettlementRefund
    {
        return PurchaseReturnSettlementRefund::with(['settlement'])->find($id);
    }

    public function createRefund(array $data): PurchaseReturnSettlementRefund
    {
        return PurchaseReturnSettlementRefund::create($data);
    }

    public function generateSettlementNo(): string
    {
        $prefix = 'PRS-' . now()->format('Ymd') . '-';
        $last = PurchaseReturnSettlement::where('settlement_number', 'like', $prefix . '%')
            ->orderByDesc('settlement_number')
            ->value('settlement_number');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
