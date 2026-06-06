<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Http\Requests\StoreSalesReturnRequest;
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
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function show(int $id): JsonResponse
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
                new OA\Property(property: 'order_id', type: 'integer', nullable: true),
                new OA\Property(property: 'location_id', type: 'integer', example: 1),
                new OA\Property(property: 'source', type: 'string', enum: ['manual', 'marketplace'], example: 'manual'),
                new OA\Property(property: 'customer_name', type: 'string'),
                new OA\Property(property: 'reason', type: 'string'),
                new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'qty'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
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
        try {
            $return = $this->returnService->create($request->validated());
            return $this->successResponse($return, 'Sales return berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/accept',
        summary: 'Accept a return (stock masuk ke gudang)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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
    public function accept(int $id, Request $request): JsonResponse
    {
        $request->validate(['processed_by' => 'required|string|max:100']);

        try {
            $return = $this->returnService->accept($id, $request->only('processed_by'));
            return $this->successResponse($return, 'Return diterima, Inbound GRN dibuat');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/reject',
        summary: 'Reject a return (stock tidak berubah)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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
    public function reject(int $id, Request $request): JsonResponse
    {
        $request->validate([
            'processed_by' => 'required|string|max:100',
            'reason'       => 'nullable|string',
        ]);

        try {
            $return = $this->returnService->reject($id, $request->only('processed_by', 'reason'));
            return $this->successResponse($return, 'Return ditolak');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/complete',
        summary: 'Mark return as complete',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
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
    public function complete(int $id, Request $request): JsonResponse
    {
        $request->validate(['processed_by' => 'required|string|max:100']);

        try {
            $return = $this->returnService->complete($id, $request->only('processed_by'));
            return $this->successResponse($return, 'Return selesai');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
