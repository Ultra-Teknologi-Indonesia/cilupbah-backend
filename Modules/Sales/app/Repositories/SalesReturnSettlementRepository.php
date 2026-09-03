<?php

namespace Modules\Sales\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesReturnSettlement;
use Modules\Sales\Models\SalesReturnSettlementInvoice;
use Modules\Sales\Models\SalesReturnSettlementRefund;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesReturnSettlementRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(SalesReturnSettlement::class)
            ->with(['salesReturn:id,return_number,status'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('return_id'),
            )
            ->allowedSorts('settlement_number', 'total_amount', 'created_at')
            ->defaultSort('-created_at');
        $query->whereHas('salesReturn', fn ($return) => WarehouseAccess::apply($return, 'location_id'));

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?SalesReturnSettlement
    {
        return SalesReturnSettlement::with(['salesReturn', 'invoices.invoice', 'refunds'])
            ->whereHas('salesReturn', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->find($id);
    }

    public function create(array $data): SalesReturnSettlement
    {
        $returnQuery = \Modules\Sales\Models\SalesReturn::whereKey($data['return_id']);
        WarehouseAccess::apply($returnQuery, 'location_id');
        if (! $returnQuery->exists()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Retur penjualan tidak ditemukan.');
        }

        return SalesReturnSettlement::create($data);
    }

    public function delete(string $id): bool
    {
        return SalesReturnSettlement::whereKey($id)
            ->whereHas('salesReturn', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->delete() > 0;
    }

    public function getInvoices(int $limit = 10)
    {
        $query = QueryBuilder::for(SalesReturnSettlementInvoice::class)
            ->with(['settlement:id,settlement_number', 'invoice:id,invoice_number,total_amount'])
            ->allowedFilters(
                AllowedFilter::exact('settlement_id'),
            )
            ->defaultSort('-created_at');
        $query->whereHas('settlement.salesReturn', fn ($return) => WarehouseAccess::apply($return, 'location_id'));

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findInvoiceById(string $id): ?SalesReturnSettlementInvoice
    {
        return SalesReturnSettlementInvoice::with(['settlement', 'invoice'])
            ->whereHas('settlement.salesReturn', fn ($return) => WarehouseAccess::apply($return, 'location_id'))
            ->find($id);
    }

    public function createInvoice(array $data): SalesReturnSettlementInvoice
    {
        $settlementQuery = SalesReturnSettlement::whereKey($data['settlement_id']);
        $settlementQuery->whereHas('salesReturn', fn ($query) => WarehouseAccess::apply($query, 'location_id'));
        if (! $settlementQuery->exists()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Settlement tidak ditemukan.');
        }

        return SalesReturnSettlementInvoice::create($data);
    }

    public function getRefunds(int $limit = 10)
    {
        $query = QueryBuilder::for(SalesReturnSettlementRefund::class)
            ->with(['settlement:id,settlement_number'])
            ->allowedFilters(
                AllowedFilter::exact('settlement_id'),
            )
            ->defaultSort('-created_at');
        $query->whereHas('settlement.salesReturn', fn ($return) => WarehouseAccess::apply($return, 'location_id'));

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findRefundById(string $id): ?SalesReturnSettlementRefund
    {
        return SalesReturnSettlementRefund::with(['settlement'])
            ->whereHas('settlement.salesReturn', fn ($return) => WarehouseAccess::apply($return, 'location_id'))
            ->find($id);
    }

    public function createRefund(array $data): SalesReturnSettlementRefund
    {
        $settlementQuery = SalesReturnSettlement::whereKey($data['settlement_id']);
        $settlementQuery->whereHas('salesReturn', fn ($query) => WarehouseAccess::apply($query, 'location_id'));
        if (! $settlementQuery->exists()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Settlement tidak ditemukan.');
        }

        return SalesReturnSettlementRefund::create($data);
    }

    public function generateSettlementNo(): string
    {
        $prefix = 'RS-' . now()->format('Ymd') . '-';

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['docnum:' . $prefix]);
        $last = SalesReturnSettlement::where('settlement_number', 'like', $prefix . '%')
            ->orderByDesc('settlement_number')
            ->first();

        if ($last) {
            $lastSeq = (int) substr($last->settlement_number, -4);
            return $prefix . str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
