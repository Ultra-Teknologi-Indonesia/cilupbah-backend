<?php

namespace Modules\Purchase\Services;

use Carbon\Carbon;
use Modules\Inventory\Models\ImpexActivity;
use Modules\Inventory\Services\ImpexActivityService;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Repositories\PurchaseOrderExportRepository;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderExportService
{
    public function __construct(
        protected PurchaseOrderExportRepository $exportRepo,
        protected ImpexActivityService $impexActivityService,
    ) {}

    public function streamList(array $filters = [], ?string $userId = null): StreamedResponse
    {
        $filename = sprintf('purchase-orders-list-%s.csv', Carbon::now()->format('Y-m-d-His'));

        $this->impexActivityService->record(
            ImpexActivity::DIRECTION_EXPORT,
            'Export List Pesanan Pembelian',
            $userId,
        );

        $query = $this->exportRepo->getListQuery($filters);

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fputs($handle, "\xEF\xBB\xBF");

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

            $query->chunk(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    $supplierName = $order->contact?->name ?? '-';
                    $locationName = $order->location?->location_name ?? '-';

                    $orderDate = $order->order_date
                        ? Carbon::parse($order->order_date)->locale('id')->isoFormat('D MMM YYYY')
                        : '-';

                    $statusLabel = match ($order->status) {
                        PurchaseOrder::STATUS_OPEN     => 'Aktif',
                        PurchaseOrder::STATUS_CLOSED   => 'Selesai',
                        PurchaseOrder::STATUS_CANCELLED=> 'Dibatalkan',
                        default                        => ucfirst((string) $order->status),
                    };

                    $billNumbers = $order->bills->pluck('bill_number')->filter()->implode(', ');

                    fputcsv($handle, [
                        $order->po_number,
                        $supplierName,
                        $locationName,
                        $orderDate,
                        $statusLabel,
                        (float) $order->total_amount,
                        $order->notes ?? '',
                        $billNumbers,
                    ]);
                }

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
        ]);
    }

    public function streamDetail(array $filters = [], ?string $userId = null): StreamedResponse
    {
        $filename = sprintf('purchase-orders-details-%s.csv', Carbon::now()->format('Y-m-d-His'));

        $this->impexActivityService->record(
            ImpexActivity::DIRECTION_EXPORT,
            'Export Detail Pesanan Pembelian',
            $userId,
        );

        $query = $this->exportRepo->getDetailQuery($filters);

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM
            fputs($handle, "\xEF\xBB\xBF");

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
                $orderDate = $row->order_date
                    ? Carbon::parse($row->order_date)->locale('id')->isoFormat('D MMM YYYY')
                    : '-';

                $description = ! empty($row->item_description)
                    ? $row->item_description
                    : ($row->product_name ?? '-');

                fputcsv($handle, [
                    $orderDate,
                    $row->po_number,
                    $row->sku ?? '-',
                    $description,
                    $row->contact_name ?? '-',
                    (float) $row->unit_price,
                    (int) $row->qty,
                    (float) $row->disc_amount,
                    (float) $row->tax_amount,
                    (float) $row->amount,
                    (float) $row->po_sub_total,
                    (float) $row->po_total_amount,
                    $row->location_name ?? '-',
                ]);

                $rowCount++;
                if ($rowCount % 500 === 0) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            }

            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            fclose($handle);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'              => 'no-cache',
        ]);
    }
}
