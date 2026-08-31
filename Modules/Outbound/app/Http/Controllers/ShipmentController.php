<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfRenderer;
use App\Services\QrCodeGenerator;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Outbound\Exceptions\ScanRejectedException;
use Modules\Outbound\Exports\ShipmentManifestExport;
use Modules\Outbound\Http\Requests\AddShipmentOrdersRequest;
use Modules\Outbound\Http\Requests\BulkManifestPdfRequest;
use Modules\Outbound\Http\Requests\CreateShipmentRequest;
use Modules\Outbound\Http\Requests\DriverCallRequest;
use Modules\Outbound\Http\Requests\RemoveShipmentOrdersRequest;
use Modules\Outbound\Http\Requests\SaveAwbRequest;
use Modules\Outbound\Http\Requests\ScanShipmentOrderRequest;
use Modules\Outbound\Http\Requests\ScanShipmentRequest;
use Modules\Outbound\Http\Requests\StoreInstantShipmentRequest;
use Modules\Outbound\Http\Requests\UpdateDriverCallRequest;
use Modules\Outbound\Http\Requests\UpdateHandoverQtyRequest;
use Modules\Outbound\Http\Resources\CompletedShipmentOrderResource;
use Modules\Outbound\Http\Resources\ShipmentOrderResource;
use Modules\Outbound\Http\Resources\ShipmentResource;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;
use Modules\Outbound\Repositories\ShipmentRepository;
use Modules\Outbound\Services\ShipmentService;
use OpenApi\Attributes as OA;
use Throwable;

#[OA\Tag(name: 'Outbound - Shipment', description: 'API Endpoints for Shipment management')]
#[OA\Schema(
    schema: 'Shipment',
    title: 'Shipment Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'shipment_no', type: 'string', example: 'SHP-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'courier_name', type: 'string', nullable: true, example: 'JNE'),
        new OA\Property(property: 'courier_code', type: 'string', nullable: true, example: 'jne'),
        new OA\Property(property: 'shipment_type', type: 'string', enum: ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO']),
        new OA\Property(property: 'shipment_date', type: 'string', format: 'date'),
        new OA\Property(property: 'status', type: 'string', enum: ['SCHEDULED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED']),
        new OA\Property(property: 'handed_over_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class ShipmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ShipmentService $shipmentService,
        protected ShipmentRepository $shipmentRepository,
        protected QrCodeGenerator $qrCodeGenerator,
        protected PdfRenderer $pdfRenderer,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/shipments',
        summary: 'Get paginated shipments',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['SCHEDULED', 'HANDED_OVER', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[courier_name]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[shipment_type]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->shipmentService->getAllPaginated($limit);

        $data->through(fn ($shipment) => new ShipmentResource($shipment));

        return $this->successResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments',
        summary: 'Create a new shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['location_id', 'shipment_type', 'shipment_date'],
                properties: [
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'courier_name', type: 'string', nullable: true),
                    new OA\Property(property: 'courier_code', type: 'string', nullable: true),
                    new OA\Property(property: 'shipment_type', type: 'string', enum: ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO']),
                    new OA\Property(property: 'shipment_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreateShipmentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->email;

        $shipment = $this->shipmentService->create($data);

        return $this->successResponse($shipment, null, 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/{id}',
        summary: 'Get shipment detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/outbound/shipments/by-courier/{courierCode}',
        summary: 'Get shipments filtered by courier code',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'courierCode', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function byCourier(string $courierCode, Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->shipmentService->getByCourier($courierCode, $limit);

        $data->through(fn ($shipment) => new ShipmentResource($shipment));

        return $this->successResponse($data);
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/completed/{type}/{courierIds}',
        summary: 'Get completed/on-delivery shipments by type and courier(s)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO', 'INSTANT'])),
            new OA\Parameter(name: 'courierIds', in: 'path', required: true, description: 'Comma-separated courier codes', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function completed(string $type, string $courierIds, Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $paginator = $this->shipmentService->getCompleted($type, $courierIds, $limit);

        $items = collect($paginator->items())
            ->map(fn ($so) => (new CompletedShipmentOrderResource($so))->toArray($request))
            ->all();

        return $this->successResponse([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/instant',
        summary: 'Get all instant courier shipments',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function instantAll(Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->shipmentService->getInstantAll($limit);

        $data->through(fn ($shipment) => new ShipmentResource($shipment));

        return $this->successResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/instant',
        summary: 'Create an instant courier shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['location_id', 'shipment_date'],
                properties: [
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'courier_name', type: 'string', nullable: true),
                    new OA\Property(property: 'courier_code', type: 'string', nullable: true),
                    new OA\Property(property: 'shipment_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
        ]
    )]
    public function storeInstant(StoreInstantShipmentRequest $request): JsonResponse
    {
        $data = $request->only(['location_id', 'shipment_date', 'courier_name', 'courier_code', 'notes', 'shipper_id']);
        $data['created_by'] = auth()->user()->email;

        $shipment = $this->shipmentService->createInstant($data);

        return $this->successResponse($shipment, null, 201);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/update-handover-qty',
        summary: 'Update qty given to courier for an order in shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'qty_given'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'qty_given', type: 'integer', minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 400, description: 'Bad Request'),
        ]
    )]
    public function updateHandoverQty(string $id, UpdateHandoverQtyRequest $request): JsonResponse
    {
        try {
            $this->shipmentService->updateHandoverQty($id, $request->order_id, $request->qty_given);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses pengiriman.',
                400,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Qty handover berhasil diperbarui.');
    }

    public function show(string $id): JsonResponse
    {
        $shipment = $this->shipmentService->getById($id);

        if (! $shipment) {
            return $this->errorResponse('Shipment tidak ditemukan.', 404);
        }

        return $this->successResponse(new ShipmentResource($shipment));
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/{id}/orders',
        summary: 'Get paginated orders for a shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function orders(string $id, Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 20));
        $data = $this->shipmentService->getOrdersPaginated($id, $limit);

        return $this->successResponse($data);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/add-orders',
        summary: 'Add orders to shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'internal_only', type: 'boolean', nullable: true, description: 'Batasi penambahan hanya untuk order manual/internal.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function addOrders(string $id, AddShipmentOrdersRequest $request): JsonResponse
    {
        $shipment = $this->shipmentService->addOrders(
            $id,
            $request->order_ids,
            $request->boolean('internal_only'),
        );

        return $this->successResponse($shipment);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/remove-orders',
        summary: 'Remove orders from shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function removeOrders(string $id, RemoveShipmentOrdersRequest $request): JsonResponse
    {
        $shipment = $this->shipmentService->removeOrders($id, $request->order_ids);

        return $this->successResponse($shipment);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/hand-over',
        summary: 'Hand over shipment to courier',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function handOver(string $id): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->handOver($id);
        } catch (\Exception $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($shipment);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/scan-order',
        summary: 'Scan order barcode to add to shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['barcode'],
                properties: [
                    new OA\Property(property: 'barcode', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function scanOrder(string $id, ScanShipmentOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->shipmentService->scanAndAddOrder($id, $request->barcode);
        } catch (ScanRejectedException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['code' => $e->reason],
                'Gagal memindai',
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memindai. Coba lagi atau laporkan ke admin.',
                422,
                ['code' => 'error', 'detail' => $e->getMessage()],
                'Gagal memindai',
            );
        }

        $shipment = (new ShipmentResource($result->shipment))->toArray($request);
        $shipment['scan_result'] = [
            'status' => $result->alreadyAdded ? 'already_added' : 'added',
            'barcode' => $result->barcode,
            'shipment_order' => (new ShipmentOrderResource($result->shipmentOrder))->toArray($request),
        ];

        return $this->successResponse($shipment);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/scan',
        summary: 'Scan shipment by barcode/shipment_no/tracking_number',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['barcode'],
                properties: [
                    new OA\Property(property: 'barcode', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function scan(ScanShipmentRequest $request): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->scanShipment($request->barcode);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memindai.',
                404,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($shipment);
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/save-awb',
        summary: 'Save airwaybill/tracking number for an order in shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_id', 'tracking_number'],
                properties: [
                    new OA\Property(property: 'order_id', type: 'string'),
                    new OA\Property(property: 'tracking_number', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function saveAwb(string $id, SaveAwbRequest $request): JsonResponse
    {
        $this->shipmentService->updateTrackingNumber($id, $request->order_id, $request->tracking_number);

        return $this->successResponse(null, 'Tracking number berhasil disimpan.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/cancel',
        summary: 'Cancel shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $shipment = $this->shipmentService->cancel($id);

        return $this->successResponse($shipment);
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/{id}/manifest-pdf',
        summary: 'Cetak manifest pengiriman sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Shipment tidak ditemukan'),
        ]
    )]
    public function manifestPdf(string $id)
    {
        $shipment = $this->shipmentService->getById($id);

        if (! $shipment) {
            return $this->errorResponse('Shipment tidak ditemukan.', 404);
        }

        try {
            $shipmentNo = $shipment->shipment_no ?? 'SHP';
            $filename = "{$shipmentNo}-manifest.pdf";

            $qrDataUri = $this->qrCodeGenerator->svgDataUri((string) $shipmentNo);

            return $this->pdfRenderer->stream('outbound::pdf.manifest', [
                'shipment' => $shipment,
                'qrDataUri' => $qrDataUri,
            ], $filename, 'a4', 'portrait');
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal membuat PDF manifest.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/outbound/shipments/{id}/manifest-excel',
        summary: 'Unduh manifest pengiriman sebagai Excel (.xlsx)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'XLSX file stream',
                content: new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ),
            new OA\Response(response: 404, description: 'Shipment tidak ditemukan'),
        ]
    )]
    public function manifestExcel(string $id)
    {
        $shipment = $this->shipmentService->getById($id);

        if (! $shipment) {
            return $this->errorResponse('Shipment tidak ditemukan.', 404);
        }

        try {
            $shipmentNo = $shipment->shipment_no ?? 'SHP';
            $filename = "{$shipmentNo}-manifest.xlsx";

            return Excel::download(new ShipmentManifestExport($shipment), $filename);
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal membuat Excel manifest.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/documents/bulk/manifest-pdf',
        summary: 'Cetak manifest untuk banyak pesanan (order_ids) dalam 1 PDF multi-halaman',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Tidak ada shipment ditemukan untuk pesanan tersebut'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function bulkManifestPdf(BulkManifestPdfRequest $request)
    {
        try {
            $orderIds = $request->validated()['order_ids'];

            $shipments = $this->shipmentRepository->getForBulkManifestPdf($orderIds);

            if ($shipments->isEmpty()) {
                return $this->errorResponse('Tidak ada shipment ditemukan untuk pesanan yang dipilih.', 404);
            }

            $qrMap = $this->qrCodeGenerator->mapDataUris(
                $shipments,
                fn ($shipment) => $shipment->id,
                fn ($shipment) => (string) ($shipment->shipment_no ?? ''),
            );

            $filename = 'Manifest-Bulk-'.now()->format('Ymd-His').'.pdf';

            return $this->pdfRenderer->stream('outbound::pdf.manifest-bulk', [
                'shipments' => $shipments,
                'qrMap' => $qrMap,
            ], $filename, 'a4', 'portrait');
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal membuat PDF manifest bulk.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/driver-call',
        summary: 'Record manual driver call for Grab/GoSend instant shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    properties: [
                        new OA\Property(property: 'driver_name', type: 'string', nullable: true),
                        new OA\Property(property: 'driver_phone', type: 'string', nullable: true),
                        new OA\Property(property: 'driver_vehicle_plate', type: 'string', nullable: true),
                        new OA\Property(property: 'driver_booking_code', type: 'string', nullable: true),
                        new OA\Property(property: 'driver_id_card', type: 'string', format: 'binary', nullable: true),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Not eligible'),
        ]
    )]
    public function driverCall(string $id, DriverCallRequest $request): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->recordDriverCall(
                $id,
                $request->only(['driver_name', 'driver_phone', 'driver_vehicle_plate', 'driver_booking_code', 'shipper_id']),
                $request->file('driver_id_card'),
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses driver.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($shipment, 'Panggilan driver berhasil dicatat.');
    }

    #[OA\Patch(
        path: '/api/v1/outbound/shipments/{id}/driver-call',
        summary: 'Update driver call data/status',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function updateDriverCall(string $id, UpdateDriverCallRequest $request): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->updateDriverCall(
                $id,
                $request->only(['driver_name', 'driver_phone', 'driver_vehicle_plate', 'driver_booking_code', 'driver_call_status', 'shipper_id']),
                $request->file('driver_id_card'),
            );
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($shipment, 'Data driver berhasil diperbarui.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/mark-delivered',
        summary: 'Manually mark shipment as delivered',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function markDelivered(string $id): JsonResponse
    {
        try {
            $shipment = $this->shipmentService->markDelivered($id);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($shipment, 'Shipment berhasil ditandai DELIVERED.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/reconcile',
        summary: 'Reconcile shipment: sync channel order statuses and return summary',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Summary of reconciliation'),
        ]
    )]
    public function reconcile(string $id): JsonResponse
    {
        try {
            $summary = $this->shipmentService->reconcile($id);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse($summary, 'Reconcile selesai.');
    }

    #[OA\Delete(
        path: '/api/v1/outbound/shipments/{id}',
        summary: 'Delete scheduled shipment',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->shipmentService->delete($id);

        return $this->successResponse(null, 'Shipment berhasil dihapus.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/shipments/{id}/refresh-tracking',
        summary: 'Refresh tracking driver on-demand (dispatch RefreshInstantTrackingJob sync)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Shipment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Diproses'),
        ]
    )]
    public function refreshTracking(string $id): JsonResponse
    {

        try {
            RefreshInstantTrackingJob::dispatchSync($id);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->successResponse(null, 'Refresh tracking diproses.', 202);
    }

    public function trackingEvents(string $id): JsonResponse
    {
        $events = $this->shipmentRepository->getTrackingEvents($id);

        return $this->successResponse($events, 'Timeline tracking shipment.');
    }
}
