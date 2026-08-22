<?php

namespace Modules\Sales\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesInvoiceItem;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class SalesInvoiceRepository
{
    public function getAllPaginated(int $limit = 10)
    {
        return QueryBuilder::for(SalesInvoice::class)
            ->with(['order:id,salesorder_no', 'location:id,location_name', 'items'])
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('location_id'),
                AllowedFilter::exact('order_id')
            )
            ->allowedSearch('invoice_number', 'customer_name')
            ->allowedSorts('invoice_number', 'invoice_date', 'due_date', 'total_amount', 'created_at')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function findById(string $id): ?SalesInvoice
    {
        return SalesInvoice::with(['order', 'location', 'items.product:id,sku,product_id', 'payments'])
            ->find($id);
    }

    public function create(array $data): SalesInvoice
    {
        return SalesInvoice::create($data);
    }

    public function createItem(array $data): SalesInvoiceItem
    {
        return SalesInvoiceItem::create($data);
    }

    public function getUnpaid(int $limit = 10)
    {
        return QueryBuilder::for(SalesInvoice::class)
            ->whereIn('status', [SalesInvoice::STATUS_DRAFT, SalesInvoice::STATUS_OPEN])
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->with(['order:id,salesorder_no', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('location_id')
            )
            ->allowedSearch('invoice_number', 'customer_name')
            ->allowedSorts('due_date', 'total_amount', 'created_at')
            ->defaultSort('due_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getOverdue(int $limit = 10)
    {
        return QueryBuilder::for(SalesInvoice::class)
            ->where('due_date', '<', now()->toDateString())
            ->whereIn('status', [SalesInvoice::STATUS_DRAFT, SalesInvoice::STATUS_OPEN])
            ->with(['order:id,salesorder_no', 'location:id,location_name'])
            ->allowedFilters(
                AllowedFilter::exact('location_id')
            )
            ->allowedSearch('invoice_number', 'customer_name')
            ->allowedSorts('due_date', 'total_amount')
            ->defaultSort('due_date')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function getSummary()
    {
        return DB::table('sales_invoices')
            ->join('locations', 'sales_invoices.location_id', '=', 'locations.id')
            ->select(
                'locations.id as location_id',
                'locations.location_name',
                DB::raw('COUNT(*) as total_invoices'),
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw('SUM(paid_amount) as total_paid'),
                DB::raw('SUM(total_amount - paid_amount) as total_outstanding'),
            )
            ->groupBy('locations.id', 'locations.location_name')
            ->orderBy('locations.location_name')
            ->get();
    }

    public function getForReturnWms(string $contactId, int $limit = 10)
    {
        return QueryBuilder::for(SalesInvoice::class)
            ->where('customer_name', $contactId)
            ->whereNot('status', SalesInvoice::STATUS_CANCELLED)
            ->select('id', 'invoice_number', 'order_id', 'customer_name', 'total_amount', 'paid_amount', 'status')
            ->defaultSort('-created_at')
            ->paginate($limit)
            ->appends(request()->query());
    }

    public function generateInvoiceNo(): string
    {
        $prefix = 'INV-' . now()->format('Ymd') . '-';

        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['docnum:' . $prefix]);
        $lastInvoice = SalesInvoice::where('invoice_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(invoice_number) DESC, invoice_number DESC')
            ->first();

        if ($lastInvoice) {
            $suffix = substr($lastInvoice->invoice_number, strlen($prefix));
            $lastSeq = is_numeric($suffix) ? (int) $suffix : 0;
            $nextSeq = $lastSeq + 1;
            return $prefix . str_pad((string) $nextSeq, max(4, strlen((string) $nextSeq)), '0', STR_PAD_LEFT);
        }

        return $prefix . '0001';
    }
}
