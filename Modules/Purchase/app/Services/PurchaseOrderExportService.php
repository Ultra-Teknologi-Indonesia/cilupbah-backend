<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\PurchaseOrder;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderExportService
{
    private const INDO_MONTHS = [
        1  => 'Jan', 2  => 'Feb', 3  => 'Mar', 4  => 'Apr',
        5  => 'Mei', 6  => 'Jun', 7  => 'Jul', 8  => 'Agt',
        9  => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    public function streamList(array $filters = []): StreamedResponse
    {
        $filename = sprintf('purchase-orders-list-%s.csv', Carbon::now()->format('Y-m-d-His'));

        $query = $this->buildBaseQuery($filters)
            ->with([
                'contact:id,name',
                'location:id,location_name',
                'bills:id,purchase_order_id,bill_number',
            ])
            ->select('purchase_orders.*')
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Header row matching _accounting-purchase-orders-v2 format
            fputcsv($handle, [
                'No.Pesanan',
                'Pemasok',
                'Lokasi',
                'Tanggal Pesanan',
                'Status',
                'Nilai',
                'Keterangan',
                'No.Penerima',
            ]);

            $rowCount = 0;

            foreach ($query->lazy(500) as $po) {
                $statusLabel = match ($po->status) {
                    PurchaseOrder::STATUS_OPEN             => 'Aktif',
                    PurchaseOrder::STATUS_PARTIAL_RECEIVED => 'Diterima Sebagian',
                    PurchaseOrder::STATUS_FULLY_RECEIVED   => 'Selesai',
                    PurchaseOrder::STATUS_DRAFT            => 'Draft',
                    default                                => ucfirst(strtolower((string) $po->status)),
                };

                $billNumbers = $po->bills->pluck('bill_number')->filter()->implode(', ');

                fputcsv($handle, [
                    $po->po_number,
                    $po->contact?->name ?? '',
                    $po->location?->location_name ?? '',
                    $this->formatIndoDate($po->order_date),
                    $statusLabel,
                    (string) round((float) $po->total_amount),
                    $po->notes ?? '',
                    $billNumbers,
                ]);

                $rowCount++;
                if ($rowCount % 500 === 0) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }

            fclose($handle);
        }, 200, $headers);
    }

    public function streamDetail(array $filters = []): StreamedResponse
    {
        $filename = sprintf('purchase-orders-details-%s.csv', Carbon::now()->format('Y-m-d-His'));

        // Optimized flat query for massive dataset (130,000+ rows) to minimize memory
        $query = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->leftJoin('contacts', 'contacts.id', '=', 'purchase_orders.contact_id')
            ->leftJoin('locations', 'locations.id', '=', 'purchase_orders.location_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'purchase_order_items.item_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->select([
                'purchase_orders.order_date',
                'purchase_orders.po_number',
                'purchase_orders.sub_total as po_sub_total',
                'purchase_orders.total_amount as po_total_amount',
                'purchase_order_items.unit_price',
                'purchase_order_items.qty',
                'purchase_order_items.disc_amount',
                'purchase_order_items.tax_amount',
                'purchase_order_items.amount',
                'purchase_order_items.description as item_description',
                'contacts.name as contact_name',
                'locations.location_name',
                'product_variants.sku',
                'products.name as product_name',
            ]);

        $this->applyFlatFilters($query, $filters);

        $query->orderByDesc('purchase_orders.order_date')
            ->orderByDesc('purchase_orders.id')
            ->orderBy('purchase_order_items.id');

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Excel
            fputs($handle, "\xEF\xBB\xBF");

            // Header row matching accounting-purchase-orders-details-v2 format
            fputcsv($handle, [
                'Transaction Date',
                'Purchase Order No.',
                'Item Code',
                'Description',
                'Contact Name',
                'Price',
                'Qty',
                'Disc Amount',
                'Tax Amount',
                'Amount',
                'Sub Total',
                'Grand Total',
                'Location Name',
            ]);

            $rowCount = 0;

            foreach ($query->cursor() as $row) {
                $description = $row->product_name ?: ($row->item_description ?: $row->sku);

                fputcsv($handle, [
                    $this->formatIndoDate($row->order_date),
                    $row->po_number,
                    $row->sku ?? '',
                    $description ?? '',
                    $row->contact_name ?? '',
                    (string) round((float) $row->unit_price),
                    (string) (int) $row->qty,
                    (string) round((float) $row->disc_amount),
                    (string) round((float) $row->tax_amount),
                    (string) round((float) $row->amount),
                    (string) round((float) $row->po_sub_total),
                    (string) round((float) $row->po_total_amount),
                    $row->location_name ?? '',
                ]);

                $rowCount++;
                if ($rowCount % 500 === 0) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }

            fclose($handle);
        }, 200, $headers);
    }

    protected function buildBaseQuery(array $filters): Builder
    {
        $query = PurchaseOrder::query();

        if (! empty($filters['location_id'])) {
            $query->where('location_id', $filters['location_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('contact_id', $filters['contact_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('order_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('order_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'ilike', $search)
                    ->orWhereHas('contact', fn ($sq) => $sq->where('name', 'ilike', $search))
                    ->orWhere('ref_no', 'ilike', $search)
                    ->orWhere('notes', 'ilike', $search);
            });
        }

        return $query;
    }

    protected function applyFlatFilters($query, array $filters): void
    {
        if (! empty($filters['location_id'])) {
            $query->where('purchase_orders.location_id', $filters['location_id']);
        }

        if (! empty($filters['contact_id'])) {
            $query->where('purchase_orders.contact_id', $filters['contact_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('purchase_orders.status', $filters['status']);
        }

        if (! empty($filters['date_from'])) {
            $query->where('purchase_orders.order_date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->where('purchase_orders.order_date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('purchase_orders.po_number', 'ilike', $search)
                    ->orWhere('contacts.name', 'ilike', $search)
                    ->orWhere('purchase_orders.ref_no', 'ilike', $search)
                    ->orWhere('product_variants.sku', 'ilike', $search)
                    ->orWhere('products.name', 'ilike', $search);
            });
        }
    }

    protected function formatIndoDate($date): string
    {
        if (! $date) {
            return '';
        }

        try {
            $c = is_string($date) ? Carbon::parse($date) : $date;
            $month = self::INDO_MONTHS[(int) $c->format('n')] ?? $c->format('M');
            return sprintf('%d %s %s', $c->format('j'), $month, $c->format('Y'));
        } catch (\Throwable) {
            return (string) $date;
        }
    }
}
