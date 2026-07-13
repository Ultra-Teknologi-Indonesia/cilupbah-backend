<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

class BulkInvoiceController extends Controller
{
    use ApiResponse;

    public function bulkPdf(Request $req)
    {
        $data = $req->validate([
            'order_ids' => 'required|array|min:1|max:200',
            'order_ids.*' => 'string|uuid',
        ]);

        $orders = SalesOrder::with('items')
            ->whereIn('id', $data['order_ids'])
            ->orderBy('created_at')
            ->get();

        if ($orders->isEmpty()) {
            return $this->errorResponse('Tidak ada pesanan ditemukan.', 404);
        }

        $pdf = new Fpdi();
        $rendered = 0;

        foreach ($orders as $order) {
            try {
                $order->shipping = (object) [
                    'full_name' => $order->shipping_full_name,
                    'phone' => $order->shipping_phone,
                    'address' => $order->shipping_address,
                    'city' => $order->shipping_city,
                    'province' => $order->shipping_province,
                    'post_code' => $order->shipping_post_code,
                ];

                $bytes = Pdf::loadView('sales::pdf.invoice', ['order' => $order])
                    ->setPaper('a4', 'portrait')
                    ->output();

                $pageCount = $pdf->setSourceFile(StreamReader::createByString($bytes));
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl = $pdf->importPage($p);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }

                $rendered++;
            } catch (Throwable $e) {
                Log::warning('Bulk invoice render failed for order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($rendered === 0) {
            return $this->errorResponse('Gagal me-render faktur.', 500);
        }

        $blob = $pdf->Output('S');
        $filename = 'Faktur-Bulk-' . now()->format('Ymd-His') . '.pdf';

        return response($blob, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'X-Rendered-Count' => (string) $rendered,
            'X-Total-Count' => (string) $orders->count(),
        ]);
    }
}
