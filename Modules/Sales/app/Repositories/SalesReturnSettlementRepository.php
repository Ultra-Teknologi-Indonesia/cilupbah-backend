<?php

namespace Modules\Sales\Repositories;

use Modules\Sales\Models\SalesReturnSettlement;
use Modules\Sales\Models\SalesReturnSettlementInvoice;
use Modules\Sales\Models\SalesReturnSettlementRefund;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesReturnSettlementRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnSettlement::class)
            ->with(['salesReturn:id,return_number,status'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('return_id'),
            )
            ->allowedSorts('settlement_number', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findById(string $id): ?SalesReturnSettlement
    {
        return SalesReturnSettlement::with(['salesReturn', 'invoices.invoice', 'refunds'])->find($id);
    }

    public function create(array $data): SalesReturnSettlement
    {
        return SalesReturnSettlement::create($data);
    }

    public function delete(string $id): bool
    {
        return SalesReturnSettlement::where('id', $id)->delete() > 0;
    }

    public function getInvoices(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnSettlementInvoice::class)
            ->with(['settlement:id,settlement_number', 'invoice:id,invoice_number,total_amount'])
            ->allowedFilters(
                AllowedFilter::exact('settlement_id'),
            )
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findInvoiceById(string $id): ?SalesReturnSettlementInvoice
    {
        return SalesReturnSettlementInvoice::with(['settlement', 'invoice'])->find($id);
    }

    public function createInvoice(array $data): SalesReturnSettlementInvoice
    {
        return SalesReturnSettlementInvoice::create($data);
    }

    public function getRefunds(int $limit = 10)
    {
        return QueryBuilder::for(SalesReturnSettlementRefund::class)
            ->with(['settlement:id,settlement_number'])
            ->allowedFilters(
                AllowedFilter::exact('settlement_id'),
            )
            ->defaultSort('-created_at')
            ->paginate($limit);
    }

    public function findRefundById(string $id): ?SalesReturnSettlementRefund
    {
        return SalesReturnSettlementRefund::with(['settlement'])->find($id);
    }

    public function createRefund(array $data): SalesReturnSettlementRefund
    {
        return SalesReturnSettlementRefund::create($data);
    }

    public function generateSettlementNo(): string
    {
        $prefix = 'RS-' . now()->format('Ymd') . '-';
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
