<?php

namespace Modules\Finance\Repositories;

use Modules\Purchase\Models\PurchasePayment;
use Modules\Sales\Models\SalesPayment;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Cash & Bank = VIEW read-only di atas pembayaran yang sudah ada (PLAN-CASHBANK.md):
 * receives = SalesPayment (uang masuk), payments = PurchasePayment (uang keluar).
 * Murni SELECT — tidak menyentuh jalur tulis modul Sales/Purchase.
 */
class CashbankRepository
{
    /** Daftar uang keluar (pembayaran ke supplier). */
    public function paginatePayments(?string $dateFrom = null, ?string $dateTo = null)
    {
        return QueryBuilder::for(PurchasePayment::class)
            ->with('bill.supplier')
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->allowedSorts('payment_date', 'amount', 'payment_number', 'created_at')
            ->defaultSort('-payment_date')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    /** Daftar uang masuk (pembayaran dari pelanggan). */
    public function paginateReceives(?string $dateFrom = null, ?string $dateTo = null)
    {
        return QueryBuilder::for(SalesPayment::class)
            ->with('invoice')
            ->when($dateFrom, fn ($q) => $q->whereDate('payment_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('payment_date', '<=', $dateTo))
            ->allowedSorts('payment_date', 'amount', 'payment_number', 'created_at')
            ->defaultSort('-payment_date')
            ->paginate(request('per_page', 10))
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
