<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\BulkInvoiceService;

class BulkInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BulkInvoiceService $bulkInvoiceService,
    ) {}

    public function bulkPdf(Request $req)
    {
        $data = $req->validate([
            'order_ids' => 'required|array|min:1|max:200',
            'order_ids.*' => 'string|uuid',
        ]);

        $result = $this->bulkInvoiceService->render($data['order_ids']);

        if ($result === null) {
            return $this->errorResponse('Tidak ada pesanan ditemukan.', 404);
        }

        if ($result['rendered'] === 0) {
            return $this->errorResponse('Gagal me-render faktur.', 500);
        }

        $filename = 'Faktur-Bulk-'.now()->format('Ymd-His').'.pdf';

        return response($result['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$filename}\"",
            'X-Rendered-Count' => (string) $result['rendered'],
            'X-Total-Count' => (string) $result['total'],
        ]);
    }
}
