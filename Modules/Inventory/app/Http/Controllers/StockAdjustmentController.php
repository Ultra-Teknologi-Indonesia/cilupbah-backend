<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\StockAdjustmentService;
use Modules\Inventory\Http\Requests\StoreStockAdjustmentRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Stock Adjustment', description: 'API Endpoints for Document-based Stock Adjustment')]
class StockAdjustmentController extends Controller
{
    public function __construct(
        protected StockAdjustmentService $adjustmentService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents',
        summary: 'Get list of stock adjustment documents',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'APPROVED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, description: 'Search by adjustment_no', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar dokumen adjustment berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $adjustments = $this->adjustmentService->getAllPaginated($limit);

        return $this->successPaginatedResponse($adjustments, 'Daftar dokumen adjustment berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/adjustments/documents/{id}',
        summary: 'Get stock adjustment document detail',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail adjustment berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $adjustment = $this->adjustmentService->getById($id);

        if (!$adjustment) {
            return $this->errorResponse('Dokumen adjustment tidak ditemukan.', 404);
        }

        return $this->successResponse($adjustment, 'Detail adjustment berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents',
        summary: 'Create a new stock adjustment document',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['transaction_date', 'location_id', 'items'],
            properties: [
                new OA\Property(property: 'transaction_date', type: 'string', format: 'date-time'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'is_beginning_balance', type: 'boolean'),
                new OA\Property(property: 'notes', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                        new OA\Property(property: 'actual_qty', type: 'integer'),
                        new OA\Property(property: 'batch_no', type: 'string', nullable: true),
                        new OA\Property(property: 'serial_no', type: 'string', nullable: true),
                        new OA\Property(property: 'notes', type: 'string', nullable: true),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Dokumen adjustment berhasil dibuat.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $adjustment = $this->adjustmentService->create($data);

            return $this->successResponse($adjustment, 'Dokumen adjustment berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents/{id}/approve',
        summary: 'Approve a stock adjustment document',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Dokumen adjustment di-approve, mutasi stok sedang diproses.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        try {
            $approvedBy = $request->user()->name ?? $request->user()->email;
            $adjustment = $this->adjustmentService->approve($id, $approvedBy);

            return $this->successResponse($adjustment, 'Dokumen adjustment di-approve, mutasi stok sedang diproses.', 202);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/adjustments/documents/{id}/cancel',
        summary: 'Cancel a stock adjustment document',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumen adjustment berhasil di-cancel.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $adjustment = $this->adjustmentService->cancel($id);

            return $this->successResponse($adjustment, 'Dokumen adjustment berhasil di-cancel.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/inventory/adjustments/documents/{id}',
        summary: 'Delete a stock adjustment document (soft delete)',
        security: [['bearerAuth' => []]],
        tags: ['Stock Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumen adjustment berhasil dihapus.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->adjustmentService->delete($id);

            return $this->successResponse(null, 'Dokumen adjustment berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
