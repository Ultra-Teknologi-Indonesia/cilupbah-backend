<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Purchase\Http\Requests\StorePurchaseOrderRequest;
use Modules\Purchase\Http\Requests\ReceivePurchaseOrderRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Orders', description: 'API Endpoints for Purchase Orders')]
class PurchaseOrderController extends Controller
{
    public function __construct(
        protected PurchaseOrderService $poService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/orders',
        summary: 'Get list of purchase orders',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[supplier_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $pos = $this->poService->getAllPaginated($limit);

        return $this->successPaginatedResponse($pos, 'Daftar PO berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/purchase/orders/receivable',
        summary: 'Get POs that can be received',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function receivable(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $pos = $this->poService->getReceivable($limit);

        return $this->successPaginatedResponse($pos, 'Daftar PO yang bisa di-receive');
    }

    #[OA\Get(
        path: '/api/v1/purchase/orders/{id}',
        summary: 'Get PO details',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'PO tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $po = $this->poService->getById($id);

        if (! $po) {
            return $this->errorResponse('PO tidak ditemukan', 404);
        }

        return $this->successResponse($po, 'Detail PO berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/purchase/orders',
        summary: 'Create a new Purchase Order',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['supplier_id', 'location_id', 'order_date', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'supplier_id', type: 'string', example: 1),
                new OA\Property(property: 'location_id', type: 'string', example: 1),
                new OA\Property(property: 'order_date', type: 'string', format: 'date', example: '2026-06-06'),
                new OA\Property(property: 'expected_date', type: 'string', format: 'date', example: '2026-06-13'),
                new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'qty', 'unit_price'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000'),
                        new OA\Property(property: 'qty', type: 'integer', example: 100),
                        new OA\Property(property: 'unit_price', type: 'number', example: 15000),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'PO berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $po = $this->poService->create($request->validated());
            return $this->successResponse($po, 'PO berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/purchase/orders/{id}/approve',
        summary: 'Approve a draft PO (set to OPEN)',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PO berhasil diapprove'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function approve(string $id): JsonResponse
    {
        try {
            $po = $this->poService->approve($id);
            return $this->successResponse($po, 'PO berhasil diapprove');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/purchase/orders/{id}/receive',
        summary: 'Receive items from PO (creates Inbound GRN)',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['received_by', 'items'],
            properties: [
                new OA\Property(property: 'received_by', type: 'string', example: 'warehouse_staff'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['purchase_order_item_id', 'qty'],
                    properties: [
                        new OA\Property(property: 'purchase_order_item_id', type: 'string', example: 1),
                        new OA\Property(property: 'qty', type: 'integer', example: 50),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Receive berhasil, Inbound GRN dibuat'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function receive(string $id, ReceivePurchaseOrderRequest $request): JsonResponse
    {
        try {
            $result = $this->poService->receive($id, $request->validated());
            return $this->successResponse($result, 'Receive berhasil, Inbound GRN dibuat');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/purchase/orders/{id}/cancel',
        summary: 'Cancel a PO',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PO berhasil dibatalkan'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $po = $this->poService->cancel($id);
            return $this->successResponse($po, 'PO berhasil dibatalkan');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Delete(
        path: '/api/v1/purchase/orders/{id}',
        summary: 'Delete a draft PO',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Orders'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PO berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->poService->delete($id);
            return $this->successResponse(null, 'PO berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
