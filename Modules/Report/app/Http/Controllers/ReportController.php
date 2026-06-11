<?php

namespace Modules\Report\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        path: '/api/v1/reports/shipping-label',
        summary: 'Print shipping label',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, description: 'Single order ID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order_ids', in: 'query', required: false, description: 'Comma-separated order IDs', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shipping label data'),
        ]
    )]
    public function shippingLabel(Request $request): JsonResponse
    {
        $filters = $request->all();
        if (is_string($filters['order_ids'] ?? null)) {
            $filters['order_ids'] = array_filter(explode(',', $filters['order_ids']));
        }
        $data = $this->reportService->shippingLabelReport($filters);
        return $this->successResponse($data, 'Shipping label berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/reports/lable/print',
        summary: 'Print shipping label (alternate route)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'order_ids', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Shipping label data'),
        ]
    )]
    public function labelPrint(Request $request): JsonResponse
    {
        return $this->shippingLabel($request);
    }

    #[OA\Get(
        path: '/api/v1/lazada/get-document',
        summary: 'Print Lazada invoice/label (placeholder)',
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'order_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lazada document data'),
        ]
    )]
    public function lazadaGetDocument(Request $request): JsonResponse
    {
        $orderId = $request->query('order_id');

        if ($orderId) {
            $order = \Modules\Sales\Models\SalesOrder::select([
                'id', 'salesorder_no', 'customer_name', 'source',
                'shipping_full_name', 'shipping_address', 'shipping_city',
                'tracking_number', 'shipping_provider',
            ])->with('items:id,order_id,sku,description,qty_in_base,price,amount')
              ->findOrFail($orderId);

            return $this->successResponse([
                'report_type' => 'lazada_document',
                'generated_at' => now()->toIso8601String(),
                'data' => $order,
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
