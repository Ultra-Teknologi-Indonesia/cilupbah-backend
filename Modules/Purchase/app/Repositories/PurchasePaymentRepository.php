<?php

namespace Modules\Purchase\Repositories;

use Modules\Purchase\Models\PurchasePayment;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PurchasePaymentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(PurchasePayment::class)
            ->with(['bill:id,bill_number,supplier_id,total_amount,paid_amount', 'bill.supplier:id,name,code'])
            ->allowedFilters(
                AllowedFilter::exact('purchase_bill_id'),
            )
            ->allowedSearch('payment_number')
            ->allowedSorts('payment_number', 'payment_date', 'amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?PurchasePayment
    {
        return PurchasePayment::with(['bill.supplier:id,name,code'])->find($id);
    }

    public function create(array $data): PurchasePayment
    {
        return PurchasePayment::create($data);
    }

    public function delete(PurchasePayment $payment): bool
    {
        return $payment->delete();
    }

    public function generatePaymentNo(): string
    {
        $prefix = 'PPAY-' . now()->format('Ymd') . '-';

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['docnum:' . $prefix]);
        $last = PurchasePayment::where('payment_number', 'like', $prefix . '%')
            ->orderByDesc('payment_number')
            ->value('payment_number');

        $seq = $last ? ((int) Str::afterLast($last, '-')) + 1 : 1;
        return $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
