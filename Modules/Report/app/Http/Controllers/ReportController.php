<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Report\Exports\CustomerListExport;
use Modules\Report\Exports\NegativeStockReportExport;
use Modules\Report\Exports\PicklistDetailPhotoExport;
use Modules\Report\Exports\PickListReportExport;
use Modules\Report\Exports\RincianPendapatanExport;
use Modules\Report\Exports\RincianPendapatanPerBarangExport;
use Modules\Report\Exports\SalesListPesananExport;
use Modules\Report\Exports\SalesProductExport;
use Modules\Report\Exports\SalesReturnExport;
use Modules\Report\Exports\SectionedReportExport;
use Modules\Report\Exports\ShipmentListReportExport;
use Modules\Report\Exports\TransferReportExport;
use Modules\Report\Http\Requests\BarcodeReportRequest;
use Modules\Report\Http\Requests\CustomerListExportRequest;
use Modules\Report\Http\Requests\HppReportRequest;
use Modules\Report\Http\Requests\InventoryStockExportRequest;
use Modules\Report\Http\Requests\LazadaGetDocumentRequest;
use Modules\Report\Http\Requests\NegativeStockExportRequest;
use Modules\Report\Http\Requests\NegativeStockRequest;
use Modules\Report\Http\Requests\OrderPerformanceExportRequest;
use Modules\Report\Http\Requests\OrderPerformancePdfRequest;
use Modules\Report\Http\Requests\PenyesuaianStokPdfRequest;
use Modules\Report\Http\Requests\PickListDetailExcelRequest;
use Modules\Report\Http\Requests\PickListDetailPdfRequest;
use Modules\Report\Http\Requests\PickListExportRequest;
use Modules\Report\Http\Requests\PickListLookupRequest;
use Modules\Report\Http\Requests\PutawayListExportRequest;
use Modules\Report\Http\Requests\PutawayListLookupRequest;
use Modules\Report\Http\Requests\PutawayListPdfRequest;
use Modules\Report\Http\Requests\PutawayPerformanceExportRequest;
use Modules\Report\Http\Requests\PutawayPerformancePdfRequest;
use Modules\Report\Http\Requests\RincianPendapatanExportRequest;
use Modules\Report\Http\Requests\SalesListExportRequest;
use Modules\Report\Http\Requests\SalesProductExportRequest;
use Modules\Report\Http\Requests\SalesProductSkuOptionsRequest;
use Modules\Report\Http\Requests\SalesReturnExportRequest;
use Modules\Report\Http\Requests\ShipmentByCourierExportRequest;
use Modules\Report\Http\Requests\ShipmentByCourierPdfRequest;
use Modules\Report\Http\Requests\ShipmentListExportRequest;
use Modules\Report\Http\Requests\TransferExportRequest;
use Modules\Report\Services\CustomerListReportService;
use Modules\Report\Services\ExportManager;
use Modules\Report\Services\LazadaDocumentService;
use Modules\Report\Services\OrderPerformanceReportService;
use Modules\Report\Services\PutawayListReportService;
use Modules\Report\Services\PutawayPerformanceReportService;
use Modules\Report\Services\ReportService;
use Modules\Report\Services\RincianPendapatanReportService;
use Modules\Report\Services\SalesListReportService;
use Modules\Report\Services\SalesProductReportService;
use Modules\Report\Services\SalesReturnReportService;
use Modules\Report\Services\ShipmentByCourierReportService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reports', description: 'API Endpoints for printable Reports')]
class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
        protected PdfRenderer $pdfRenderer,
    ) {}

    private function queueExport(ExportManager $exports, string $type, array $params): JsonResponse
    {
        $job = $exports->queue(request()->user(), $type, $params);

        return $this->successResponse(['export_id' => $job->id, 'status' => $job->status], null, 202);
    }

    public function negativeStockExportAsync(NegativeStockExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'negative-stock', $request->validated());
    }

    public function transferExportAsync(TransferExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'transfer', $request->validated());
    }

    public function orderPerformanceExportAsync(OrderPerformanceExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'order-performance', $request->validated());
    }

    public function putawayPerformanceExportAsync(PutawayPerformanceExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'putaway-performance', $request->validated());
    }

    public function putawayListExportAsync(PutawayListExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'putaway-list', $request->validated());
    }

    public function shipmentByCourierExportAsync(ShipmentByCourierExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'shipment-by-courier', $request->validated());
    }

    public function pickListDetailExcelAsync(PickListDetailExcelRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'picklist-detail-photo', $request->validated());
    }

    public function inventoryStockExportAsync(InventoryStockExportRequest $request, ExportManager $exports): JsonResponse
    {
        $filters = $request->normalized();
        $type = $filters['report_type'] === 'by_rack' ? 'inventory-rack' : 'inventory-stock';

        return $this->queueExport($exports, $type, $filters);
    }

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
        $payload = $this->reportService->penyesuaianStokPayload($validated);

        $filename = sprintf(
            'Daftar-Penyesuaian-Stok_%s_%s.pdf',
            $validated['start_date'],
            $validated['end_date'],
        );

        return $this->pdfRenderer->response(
            'report::pdf.penyesuaian-stok',
            $payload,
            $filename,
            $validated['download'] ?? false,
        );
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
    public function negativeStock(NegativeStockRequest $request): JsonResponse
    {
        $result = $this->reportService->negativeStockReport($request->validated());

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
    public function negativeStockExport(NegativeStockExportRequest $request)
    {
        $validated = $request->validated();

        $export = new NegativeStockReportExport($this->reportService, $validated);

        $filename = sprintf(
            'riwayat-stok-minus_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download($export, $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/transfer/export',
        summary: 'Export Laporan Gudang - Transfer (masuk/keluar) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function transferExport(TransferExportRequest $request)
    {
        $validated = $request->validated();

        $export = new TransferReportExport($this->reportService, $validated);

        $filename = sprintf(
            'laporan-transfer-%s_%s_%s.xlsx',
            $validated['jenis'],
            $validated['from'],
            $validated['to'],
        );

        return Excel::download($export, $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/pick-list/export',
        summary: 'Export Daftar Picklist ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function pickListExport(PickListExportRequest $request)
    {
        $validated = $request->validated();

        $export = new PickListReportExport($this->reportService, $validated);

        $filename = sprintf(
            'daftar-picklist_%s_%s.xlsx',
            $validated['from'],
            $validated['to'],
        );

        return Excel::download($export, $filename);
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/pick-list/pdf',
        summary: 'Cetak Detail Picklist ke PDF',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'PDF stream')]
    )]
    public function pickListDetailPdf(PickListDetailPdfRequest $request): Response
    {
        $validated = $request->validated();
        $data = $this->reportService->pickListDetailPayload(
            $validated['picklist_id'],
            $validated['order_ids'] ?? null,
        );

        $filename = sprintf('Detail-Picklist_%s.pdf', substr($validated['picklist_id'], 0, 8));

        return $this->pdfRenderer->response(
            'report::pdf.detail-picklist',
            $data,
            $filename,
            $validated['download'] ?? false,
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/pick-list/lookup',
        summary: 'Cari picklist untuk combobox laporan',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'Daftar picklist')]
    )]
    public function pickListLookup(PickListLookupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse($this->reportService->pickListLookup(
            $validated['search'] ?? null,
            (int) ($validated['per_page'] ?? 20),
        ));
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/shipment/export',
        summary: 'Export Daftar Pengiriman (manifest) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function shipmentListExport(ShipmentListExportRequest $request)
    {

        ini_set('memory_limit', '-1');
        set_time_limit(0);

        $validated = $request->validated();

        $export = new ShipmentListReportExport($this->reportService, $validated);

        $filename = sprintf(
            'daftar-pengiriman_%s_%s.xlsx',
            $validated['from'],
            $validated['to'],
        );

        return Excel::download($export, $filename);
    }

    public function shipmentListExportAsync(ShipmentListExportRequest $request, ExportManager $exports): JsonResponse
    {
        return $this->queueExport($exports, 'shipment-list', $request->validated());
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/shipment/options',
        summary: 'Opsi filter Daftar Pengiriman (kurir & status channel)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'Opsi filter')]
    )]
    public function shipmentFilterOptions(): JsonResponse
    {
        return $this->successResponse($this->reportService->shipmentFilterOptions());
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/order-performance/pdf',
        summary: 'Cetak Laporan Performa Proses Pesanan (PDF)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'PDF stream')]
    )]
    public function orderPerformancePdf(OrderPerformancePdfRequest $request, OrderPerformanceReportService $service): Response
    {
        $validated = $request->validated();
        $payload = $service->pdfPayload($validated['jenis'], $validated['mode'] === 'detail', $validated);

        $filename = sprintf(
            'Laporan-Performa-%s-%s_%s_%s.pdf',
            ucfirst($validated['jenis']),
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return $this->pdfRenderer->response(
            $payload['view'],
            $payload['data'],
            $filename,
            $validated['download'] ?? false,
            'a4',
            $payload['orientation'],
        );
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/putaway-performance/pdf',
        summary: 'Cetak Laporan Performa Penempatan (PDF)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'PDF stream')]
    )]
    public function putawayPerformancePdf(PutawayPerformancePdfRequest $request, PutawayPerformanceReportService $service): Response
    {
        $validated = $request->validated();
        $payload = $service->pdfPayload($validated['mode'] === 'detail', $validated);

        $filename = sprintf(
            'Laporan-Performa-Penempatan-%s_%s_%s.pdf',
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return $this->pdfRenderer->response(
            $payload['view'],
            $payload['data'],
            $filename,
            $validated['download'] ?? false,
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/putaway-list/lookup',
        summary: 'Nomor penempatan pada tanggal & lokasi tertentu',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'Daftar nomor penempatan')]
    )]
    public function putawayListLookup(PutawayListLookupRequest $request, PutawayListReportService $service): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse($service->lookup(
            $validated['date'],
            $validated['location_id'],
        ));
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/putaway-list/pdf',
        summary: 'Cetak Daftar Penempatan Barang (PDF)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'PDF stream')]
    )]
    public function putawayListPdf(PutawayListPdfRequest $request, PutawayListReportService $service): Response
    {
        $validated = $request->validated();
        $payload = $service->pdfPayload(
            $validated['date'],
            $validated['location_id'],
            $validated['putaway_ids'] ?? [],
        );

        $filename = sprintf('Daftar-Penempatan-Barang_%s.pdf', $validated['date']);

        return $this->pdfRenderer->response(
            $payload['view'],
            $payload['data'],
            $filename,
            $validated['download'] ?? false,
        );
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/shipment-by-courier/pdf',
        summary: 'Cetak Laporan Pengiriman Berdasarkan Ekspedisi (PDF)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'PDF stream')]
    )]
    public function shipmentByCourierPdf(ShipmentByCourierPdfRequest $request, ShipmentByCourierReportService $service): Response
    {
        $validated = $request->validated();
        $payload = $service->pdfPayload($validated['mode'] === 'detail', $validated);

        $filename = sprintf(
            'Laporan-Pengiriman-Ekspedisi-%s_%s_%s.pdf',
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return $this->pdfRenderer->response(
            $payload['view'],
            $payload['data'],
            $filename,
            $validated['download'] ?? false,
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/order-performance/export',
        summary: 'Export Laporan Performa Proses Pesanan ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function orderPerformanceExport(OrderPerformanceExportRequest $request)
    {
        $validated = $request->validated();

        $report = app(OrderPerformanceReportService::class)->sectioned(
            $validated['jenis'],
            $validated['mode'] === 'detail',
            $validated,
        );

        $filename = sprintf(
            'Laporan-Performa-%s-%s_%s_%s.xlsx',
            ucfirst($validated['jenis']),
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return Excel::download(new SectionedReportExport($report), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/putaway-performance/export',
        summary: 'Export Laporan Performa Penempatan ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function putawayPerformanceExport(PutawayPerformanceExportRequest $request)
    {
        $validated = $request->validated();

        $report = app(PutawayPerformanceReportService::class)
            ->sectioned($validated['mode'] === 'detail', $validated);

        $filename = sprintf(
            'Laporan-Performa-Penempatan-%s_%s_%s.xlsx',
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return Excel::download(new SectionedReportExport($report), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/putaway-list/export',
        summary: 'Export Daftar Penempatan Barang ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function putawayListExport(PutawayListExportRequest $request)
    {
        $validated = $request->validated();

        $report = app(PutawayListReportService::class)->sectioned(
            $validated['date'],
            $validated['location_id'],
            $validated['putaway_ids'] ?? [],
        );

        $filename = sprintf('Daftar-Penempatan-Barang_%s.xlsx', $validated['date']);

        return Excel::download(new SectionedReportExport($report), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/wms/shipment-by-courier/export',
        summary: 'Export Laporan Pengiriman Berdasarkan Ekspedisi ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function shipmentByCourierExport(ShipmentByCourierExportRequest $request)
    {
        $validated = $request->validated();

        $report = app(ShipmentByCourierReportService::class)
            ->sectioned($validated['mode'] === 'detail', $validated);

        $filename = sprintf(
            'Laporan-Pengiriman-Ekspedisi-%s_%s_%s.xlsx',
            ucfirst($validated['mode']),
            $validated['from'],
            $validated['to'],
        );

        return Excel::download(new SectionedReportExport($report), $filename);
    }

    #[OA\Post(
        path: '/api/v1/reports/wms/pick-list/xlsx',
        summary: 'Export Detail Picklist ke XLSX (dengan foto)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function pickListDetailExcel(PickListDetailExcelRequest $request)
    {
        $validated = $request->validated();

        $data = $this->reportService->pickListDetailData(
            $validated['picklist_id'],
            $validated['order_ids'] ?? null,
        );

        $filename = sprintf('Detail-Picklist_%s.xlsx', substr($validated['picklist_id'], 0, 8));

        return Excel::download(
            new PicklistDetailPhotoExport($data['picklist'], $data['groups']),
            $filename,
        );
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/list/export',
        summary: 'Export Daftar Penjualan (Referensi Pesanan) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function salesListExport(SalesListExportRequest $request)
    {
        $validated = $request->validated();

        $query = app(SalesListReportService::class)->query($validated);

        $filename = sprintf(
            'Daftar-Penjualan_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download(new SalesListPesananExport($query), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/product/sku-options',
        summary: 'Opsi SKU (varian) untuk filter Laporan Penjualan Produk — paginated + gambar',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Daftar opsi SKU paginated')]
    )]
    public function salesProductSkuOptions(SalesProductSkuOptionsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = app(SalesProductReportService::class)
            ->skuOptions($validated['search'] ?? null, $validated['per_page'] ?? 20);

        return $this->successResponse($result['data'], 'Opsi SKU berhasil diambil.', 200, $result['meta']);
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/product/export',
        summary: 'Export Laporan Penjualan Produk (item-level) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
            new OA\Parameter(name: 'item_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function salesProductExport(SalesProductExportRequest $request)
    {
        $validated = $request->validated();

        $query = app(SalesProductReportService::class)->query($validated);

        $filename = sprintf(
            'Daftar-Penjualan-Produk_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download(new SalesProductExport($query), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/return/export',
        summary: 'Export Laporan Retur Penjualan (item-level) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function salesReturnExport(SalesReturnExportRequest $request)
    {
        $validated = $request->validated();

        $query = app(SalesReturnReportService::class)->query($validated);

        $filename = sprintf(
            'Daftar-Retur-Penjualan_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download(new SalesReturnExport($query), $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/income/export',
        summary: 'Export Rincian Pendapatan (invoice-level / per barang) ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'jenis', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['rincian', 'per_barang'])),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'item_ids[]', in: 'query', required: false, schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function rincianPendapatanExport(RincianPendapatanExportRequest $request)
    {
        $validated = $request->validated();

        $mode = $validated['jenis'] ?? RincianPendapatanReportService::MODE_RINCIAN;
        $query = app(RincianPendapatanReportService::class)->query($mode, $validated);

        $export = $mode === RincianPendapatanReportService::MODE_PER_BARANG
            ? new RincianPendapatanPerBarangExport($query)
            : new RincianPendapatanExport($query);

        $filename = sprintf(
            'Rincian-Pendapatan%s_%s_%s.xlsx',
            $mode === RincianPendapatanReportService::MODE_PER_BARANG ? '-Barang' : '',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download($export, $filename);
    }

    #[OA\Get(
        path: '/api/v1/reports/sales/customer/export',
        summary: 'Export Daftar Pelanggan ke XLSX',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function customerListExport(CustomerListExportRequest $request)
    {
        $validated = $request->validated();

        $query = app(CustomerListReportService::class)->query($validated);

        $filename = sprintf(
            'Daftar-Pelanggan_%s_%s.xlsx',
            $validated['from'] ?? 'semua',
            $validated['to'] ?? now()->format('Y-m-d'),
        );

        return Excel::download(new CustomerListExport($query), $filename);
    }

    public function lazadaGetDocument(LazadaGetDocumentRequest $request, LazadaDocumentService $service): JsonResponse
    {
        $validated = $request->validated();

        $result = $service->resolve(
            $validated['order_id'] ?? null,
            $validated['shop_id'] ?? null,
            $validated['doc_type'] ?? 'shippingLabel',
        );

        return $this->successResponse($result['payload'], $result['message']);
    }
}
