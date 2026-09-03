<?php

namespace Modules\Sales\Repositories;

use App\Support\WarehouseAccess;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesPayment;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesPaymentRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        $query = QueryBuilder::for(SalesPayment::class)
            ->with(['invoice:id,invoice_number,order_id,total_amount,paid_amount,status'])
            ->allowedFilters(
                AllowedFilter::exact('sales_invoice_id'),
                AllowedFilter::exact('payment_method')
            )
            ->allowedSearch('payment_number', 'reference_no')
            ->allowedSorts('payment_number', 'payment_date', 'amount', 'created_at')
            ->defaultSort('-created_at');
        $query->whereHas('invoice', fn ($invoice) => WarehouseAccess::apply($invoice, 'location_id'));

        return $query->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?SalesPayment
    {
        return SalesPayment::with(['invoice'])
            ->whereHas('invoice', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->find($id);
    }

    public function create(array $data): SalesPayment
    {
        $invoiceQuery = \Modules\Sales\Models\SalesInvoice::whereKey($data['sales_invoice_id']);
        WarehouseAccess::apply($invoiceQuery, 'location_id');
        if (! $invoiceQuery->exists()) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Invoice tidak ditemukan.');
        }

        return SalesPayment::create($data);
    }

    public function delete(string $id): bool
    {
        return SalesPayment::whereKey($id)
            ->whereHas('invoice', fn ($invoice) => WarehouseAccess::apply($invoice, 'location_id'))
            ->delete() > 0;
    }

    public function generatePaymentNo(): string
    {
        $prefix = 'PAY-' . now()->format('Ymd') . '-';

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['docnum:' . $prefix]);
        $last = SalesPayment::where('payment_number', 'like', $prefix . '%')
            ->orderByDesc('payment_number')
            ->first();

        if ($last) {
            $lastSeq = (int) substr($last->payment_number, -4);
            return $prefix . str_pad($lastSeq + 1, 4, '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
