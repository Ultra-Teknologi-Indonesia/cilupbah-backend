<?php

namespace Modules\Finance\Repositories;

use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesPayment;
use Spatie\QueryBuilder\QueryBuilder;

class CashbankRepository
{

    public function paginatePayments(?string $dateFrom = null, ?string $dateTo = null)
    {
        return QueryBuilder::for(PurchasePayment::class)
            ->with('bill.supplier')
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->allowedSorts('payment_date', 'amount', 'payment_number', 'created_at')
            ->defaultSort('-payment_date')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function paginateReceives(?string $dateFrom = null, ?string $dateTo = null)
    {
        return QueryBuilder::for(SalesPayment::class)
            ->with('invoice')
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->allowedSorts('payment_date', 'amount', 'payment_number', 'created_at')
            ->defaultSort('-payment_date')
            ->paginate(request('per_page', 20))
            ->appends(request()->query());
    }

    public function findPayment(string $id): ?PurchasePayment
    {
        return PurchasePayment::with('bill.supplier')->find($id);
    }

    public function findReceive(string $id): ?SalesPayment
    {
        return SalesPayment::with('invoice')->find($id);
    }
}
