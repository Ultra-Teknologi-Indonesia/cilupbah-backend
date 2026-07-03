<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exports\SalesReturnReportExport;
use Modules\Sales\Http\Requests\StoreSalesReturnRequest;
use Modules\Sales\Http\Resources\SalesReturnReportResource;
use Modules\Sales\Services\SalesReturnService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Returns', description: 'API Endpoints for Sales Returns')]
class SalesReturnController extends Controller
{
    public function __construct(
        protected SalesReturnService $returnService
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/returns',
        summary: 'Get list of sales returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[source]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $returns = $this->returnService->getAllPaginated($limit);

        return $this->successPaginatedResponse($returns, 'Daftar sales return berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/unprocessed',
        summary: 'Get unprocessed marketplace returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function unprocessed(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $returns = $this->returnService->getUnprocessedMarketplace($limit);

        return $this->successPaginatedResponse($returns, 'Daftar marketplace return yang belum diproses');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/{id}',
        summary: 'Get sales return details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        return $this->successResponse($return, 'Detail sales return berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns',
        summary: 'Create a sales return (with or without order/invoice)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string', nullable: true),
                new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
                new OA\Property(property: 'source', type: 'string', enum: ['manual', 'marketplace'], example: 'manual'),
                new OA\Property(property: 'customer_name', type: 'string'),
                new OA\Property(property: 'reason', type: 'string'),
                new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'qty'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                        new OA\Property(property: 'qty', type: 'integer', example: 2),
                        new OA\Property(property: 'condition', type: 'string', enum: ['GOOD', 'DAMAGE']),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Sales return berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->create($request->validated());

        return $this->successResponse($return, 'Sales return berhasil dibuat', 201);
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/accept',
        summary: 'Accept a return (stock masuk ke gudang)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'warehouse_staff'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return diterima, Inbound GRN dibuat'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function accept(string $id, Request $request): JsonResponse
    {
        $request->validate(['processed_by' => 'required|string|max:100']);

        $return = $this->returnService->accept($id, $request->only('processed_by'));

        return $this->successResponse($return, 'Return diterima, Inbound GRN dibuat');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/reject',
        summary: 'Reject a return (stock tidak berubah)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'warehouse_staff'),
                new OA\Property(property: 'reason', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return ditolak'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function reject(string $id, Request $request): JsonResponse
    {
        $request->validate([
            'processed_by' => 'required|string|max:100',
            'reason'       => 'nullable|string',
        ]);

        $return = $this->returnService->reject($id, $request->only('processed_by', 'reason'));

        return $this->successResponse($return, 'Return ditolak');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/complete',
        summary: 'Mark return as complete',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'admin'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return selesai'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function complete(string $id, Request $request): JsonResponse
    {
        $request->validate(['processed_by' => 'required|string|max:100']);

        $return = $this->returnService->complete($id, $request->only('processed_by'));

        return $this->successResponse($return, 'Return selesai');
    }

    #[OA\Get(
        path: '/api/v1/sales/sales-returns/unpaid',
        summary: 'Get outstanding (unpaid) returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $returns = $this->returnService->getUnpaidReturns($limit);

        return $this->successPaginatedResponse($returns, 'Daftar return belum dibayar');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items',
        summary: 'Get all return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[condition]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function allItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->returnService->getAllReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items/rejected',
        summary: 'Get rejected return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function rejectedItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->returnService->getRejectedReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return yang ditolak');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items/resolved',
        summary: 'Get resolved return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function resolvedItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->returnService->getResolvedReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return yang resolved');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/report',
        summary: 'Laporan retur detail (settlement + refund per baris)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel_shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['manual', 'marketplace'])),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function report(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date|after_or_equal:date_from',
            'location_id'     => 'nullable|string',
            'channel_shop_id' => 'nullable|string',
            'status'          => 'nullable|string|max:20',
            'source'          => 'nullable|in:manual,marketplace',
            'limit'           => 'nullable|integer|min:1|max:200',
        ]);

        $limit = (int) ($validated['limit'] ?? 20);
        $rows = $this->returnService->getReportPaginated($validated, $limit);

        return $this->successPaginatedResponse(
            SalesReturnReportResource::collection($rows),
            'Laporan retur berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/report/export',
        summary: 'Unduh XLSX laporan retur detail',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel_shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function reportExport(Request $request)
    {
        $validated = $request->validate([
            'date_from'       => 'nullable|date',
            'date_to'         => 'nullable|date|after_or_equal:date_from',
            'location_id'     => 'nullable|string',
            'channel_shop_id' => 'nullable|string',
            'status'          => 'nullable|string|max:20',
            'source'          => 'nullable|in:manual,marketplace',
        ]);

        $today = now()->toDateString();
        $dateFrom = $validated['date_from'] ?? null;
        $dateTo   = $validated['date_to']   ?? null;

        $export = new SalesReturnReportExport(
            dateFrom:      $dateFrom,
            dateTo:        $dateTo,
            locationId:    $validated['location_id']     ?? null,
            channelShopId: $validated['channel_shop_id'] ?? null,
            status:        $validated['status']          ?? null,
            source:        $validated['source']          ?? null,
        );

        $filename = sprintf(
            'laporan-retur-%s-%s.xlsx',
            $dateFrom ?? 'semua',
            $dateTo ?? $today
        );

        return Excel::download($export, $filename);
    }
}
