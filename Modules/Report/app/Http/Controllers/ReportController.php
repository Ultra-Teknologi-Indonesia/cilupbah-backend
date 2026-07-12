<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Report\Exports\NegativeStockReportExport;
use Modules\Report\Http\Requests\BarcodeReportRequest;
use Modules\Report\Http\Requests\HppReportRequest;
use Modules\Report\Http\Requests\PenyesuaianStokPdfRequest;
use Modules\Report\Http\Resources\LazadaDocumentResource;
use Modules\Report\Services\ReportService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'API Endpoints for printable Reports')]
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService
    ) {}

    #[OA\Get(
        path: '/api/v1/reports/putaway',
        summary: 'Putaway report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway report data'),
        ]
    )]
    public function putaway(Request $request): JsonResponse
    {
        $data = $this->reportService->putawayReport($request->all());
        return $this->successResponse($data, 'Putaway report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/receive',
        summary: 'Receive bill (PO) report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Receive bill report data'),
        ]
    )]
    public function receive(Request $request): JsonResponse
    {
        $data = $this->reportService->receiveBillReport($request->all());
        return $this->successResponse($data, 'Receive bill report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/adjustment',
        summary: 'Stock adjustment report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock adjustment report data'),
        ]
    )]
    public function adjustment(Request $request): JsonResponse
    {
        $data = $this->reportService->adjustmentReport($request->all());
        return $this->successResponse($data, 'Stock adjustment report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/stock-opname',
        summary: 'Stock opname report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock opname report data'),
        ]
    )]
    public function stockOpname(Request $request): JsonResponse
    {
        $data = $this->reportService->stockOpnameReport($request->all());
        return $this->successResponse($data, 'Stock opname report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/purchaseorder',
        summary: 'Purchase order detail report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'supplier_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Purchase order report data'),
        ]
    )]
    public function purchaseOrder(Request $request): JsonResponse
    {
        $data = $this->reportService->purchaseOrderReport($request->all());
        return $this->successResponse($data, 'Purchase order report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/invoice',
        summary: 'Print invoice report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Invoice report data'),
        ]
    )]
    public function invoice(Request $request): JsonResponse
    {
        $data = $this->reportService->invoiceReport($request->all());
        return $this->successResponse($data, 'Invoice report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/consign',
        summary: 'Receive bill consignment report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Consignment bill report data'),
        ]
    )]
    public function consign(Request $request): JsonResponse
    {
        $data = $this->reportService->consignReport($request->all());
        return $this->successResponse($data, 'Consignment bill report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/item-receive-notplace',
        summary: 'Received but not placed items report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item receive not placed report data'),
        ]
    )]
    public function itemReceiveNotPlace(Request $request): JsonResponse
    {
        $data = $this->reportService->itemReceiveNotPlaceReport($request->all());
        return $this->successResponse($data, 'Item receive not placed report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/pick-list',
        summary: 'Print picklist report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Picklist report data'),
        ]
    )]
    public function pickList(Request $request): JsonResponse
    {
        $data = $this->reportService->pickListReport($request->all());
        return $this->successResponse($data, 'Picklist report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/shipping-manifest',
        summary: 'Shipping manifest / proof of delivery report',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'courier_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shipping manifest report data'),
        ]
    )]
    public function shippingManifest(Request $request): JsonResponse
    {
        $data = $this->reportService->shippingManifestReport($request->all());
        return $this->successResponse($data, 'Shipping manifest report berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/lazada/get-document',
        summary: 'Ambil dokumen order Lazada (invoice/shippingLabel/carrierManifest)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'order_id', in: 'query', required: false, description: 'Dengan shop_id: nomor order Lazada. Tanpa shop_id: id SalesOrder lokal.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'shop_id', in: 'query', required: false, description: 'Seller ID Lazada — bila diisi, dokumen diambil langsung dari API Lazada', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'doc_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['invoice', 'shippingLabel', 'carrierManifest'], default: 'shippingLabel')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lazada document data'),
            new OA\Response(response: 422, description: 'Gagal mengambil dari Lazada'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/reports/hpp',
        summary: 'Laporan Harga Pokok Penjualan (HPP) untuk periode tertentu',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'HPP report data'),
        ]
    )]
    public function hpp(HppReportRequest $request): JsonResponse
    {
        $data = $this->reportService->hppReport(
            $request->input('date_from'),
            $request->input('date_to'),
            $request->input('location_id'),
        );

        return $this->successResponse($data, 'Laporan HPP berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/reports/barcode/pdf',
        summary: 'Cetak PDF label QR barang (per SKU / SKU induk; Tanpa Harga / Default / Online)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [
            new OA\Response(response: 200, description: 'PDF label QR barang.'),
            new OA\Response(response: 422, description: 'Validasi gagal.'),
        ]
    )]
    public function barcodePdf(BarcodeReportRequest $request): Response
    {
        $validated = $request->validated();
        $pdf = $this->reportService->barcodePdf($validated);

        $filename = sprintf('Barcode-Barang_%s.pdf', now()->format('Ymd-His'));
        $disposition = ($validated['download'] ?? false) ? 'attachment' : 'inline';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }

    #[OA\Post(
        path: '/api/v1/reports/penyesuaian-stok/pdf',
        summary: 'Cetak PDF Daftar Penyesuaian Stok (periode, multi produk, multi lokasi)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [
            new OA\Response(response: 200, description: 'PDF Daftar Penyesuaian Stok.'),
            new OA\Response(response: 422, description: 'Validasi gagal.'),
        ]
    )]
    public function penyesuaianStokPdf(PenyesuaianStokPdfRequest $request): Response
    {
        $validated = $request->validated();
        $pdf = $this->reportService->penyesuaianStokBuild($validated);

        $filename = sprintf(
            'Daftar-Penyesuaian-Stok_%s_%s.pdf',
            $validated['start_date'],
            $validated['end_date'],
        );

        $disposition = ($validated['download'] ?? false) ? 'attachment' : 'inline';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }

    #[OA\Get(
        path: '/api/v1/reports/negative-stock',
        summary: 'Riwayat Stok Minus per SKU + Lokasi + Rak (dari inventory_movements.balance < 0)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'still_negative', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar riwayat stok minus')]
    )]
    public function negativeStock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'location_id' => 'nullable|uuid',
            'search' => 'nullable|string|max:200',
            'still_negative' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $result = $this->reportService->negativeStockReport($validated);

        return $this->successResponse(
            $result['items'],
            'Riwayat stok minus berhasil diambil.',
            200,
            $result['meta'],
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/negative-stock/export',
        summary: 'Export Riwayat Stok Minus ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function negativeStockExport(Request $request)
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'location_id' => 'nullable|uuid',
            'search' => 'nullable|string|max:200',
            'still_negative' => 'nullable|boolean',
        ]);

        $export = new NegativeStockReportExport($this->reportService, $validated);

        $filename = sprintf(
            'riwayat-stok-minus_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download($export, $filename);
    }

    public function lazadaGetDocument(Request $request): JsonResponse
    {
        $orderId = $request->query('order_id');

        if ($request->filled('shop_id') && $orderId) {
            try {
                $document = app(\Modules\Channel\Services\LazadaOrderService::class)->getDocument(
                    (string) $request->query('shop_id'),
                    (string) $orderId,
                    (string) $request->query('doc_type', 'shippingLabel')
                );

                return $this->successResponse([
                    'report_type' => 'lazada_document',
                    'generated_at' => now()->toIso8601String(),
                    'data' => $document,
                ], 'Dokumen Lazada berhasil diambil.');
            } catch (\Exception $e) {
                return $this->errorResponse(
                    'Gagal mengambil dokumen Lazada.',
                    422,
                    ['detail' => $e->getMessage()],
                    'Terjadi kesalahan',
                );
            }
        }

        if ($orderId) {
            $order = $this->reportService->lazadaOrder((string) $orderId);

            return $this->successResponse([
                'report_type' => 'lazada_document',
                'generated_at' => now()->toIso8601String(),
                'data' => new LazadaDocumentResource($order),
            ], 'Lazada document berhasil diambil.');
        }

        return $this->successResponse([
            'report_type' => 'lazada_document',
            'generated_at' => now()->toIso8601String(),
            'data' => null,
            'message' => 'Integrasi Lazada belum tersedia. Gunakan parameter order_id untuk mengambil data order.',
        ], 'Lazada document placeholder.');
    }
}
