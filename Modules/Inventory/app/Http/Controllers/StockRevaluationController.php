<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\StockRevaluationService;
use Modules\Inventory\Http\Requests\StoreStockRevaluationRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Stock Revaluation', description: 'API Endpoints for Stock Revaluation')]
class StockRevaluationController extends Controller
{
    public function __construct(
        protected StockRevaluationService $revaluationService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/revaluations',
        summary: 'Get list of stock revaluations',
        security: [['bearerAuth' => []]],
        tags: ['Stock Revaluation'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'APPROVED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar revaluasi berhasil diambil.'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $revaluations = $this->revaluationService->getAllPaginated($limit);

        return $this->successPaginatedResponse($revaluations, 'Daftar revaluasi berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/revaluations',
        summary: 'Create a stock revaluation',
        security: [['bearerAuth' => []]],
        tags: ['Stock Revaluation'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id', 'items'],
            properties: [
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                        new OA\Property(property: 'new_cost', type: 'number', format: 'float'),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Revaluasi berhasil dibuat.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreStockRevaluationRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $revaluation = $this->revaluationService->create($data);

            return $this->successResponse($revaluation, 'Revaluasi berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/revaluations/{id}',
        summary: 'Get revaluation detail',
        security: [['bearerAuth' => []]],
        tags: ['Stock Revaluation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail revaluasi berhasil diambil.'),
            new OA\Response(response: 404, description: 'Tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $revaluation = $this->revaluationService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Dokumen revaluasi tidak ditemukan.', 404);
        }

        if (!$revaluation) {
            return $this->errorResponse('Dokumen revaluasi tidak ditemukan.', 404);
        }

        return $this->successResponse($revaluation, 'Detail revaluasi berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/revaluations/{id}/approve',
        summary: 'Approve revaluation and update avg_cost',
        security: [['bearerAuth' => []]],
        tags: ['Stock Revaluation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Revaluasi berhasil di-approve, avg_cost diperbarui.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function approve(Request $request, string $id): JsonResponse
    {
        try {
            $approvedBy = $request->user()->name ?? $request->user()->email;
            $revaluation = $this->revaluationService->approve($id, $approvedBy);

            return $this->successResponse($revaluation, 'Revaluasi berhasil di-approve, avg_cost diperbarui.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/revaluations/{id}/cancel',
        summary: 'Cancel a stock revaluation',
        security: [['bearerAuth' => []]],
        tags: ['Stock Revaluation'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Revaluasi berhasil di-cancel.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $revaluation = $this->revaluationService->cancel($id);

            return $this->successResponse($revaluation, 'Revaluasi berhasil di-cancel.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
