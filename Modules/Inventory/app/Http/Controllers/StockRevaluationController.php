<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\StockRevaluationService;
use Modules\Inventory\Http\Requests\StoreStockRevaluationRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Amount Adjustment', description: 'API Endpoints for Penyesuaian Nilai (Amount Adjustment)')]
class StockRevaluationController extends Controller
{
    public function __construct(
        protected StockRevaluationService $revaluationService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/revaluations',
        summary: 'Get list of amount adjustments',
        security: [['bearerAuth' => []]],
        tags: ['Amount Adjustment'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['APPROVED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar penyesuaian nilai berhasil diambil.'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $revaluations = $this->revaluationService->getAllPaginated($limit);

        return $this->successPaginatedResponse($revaluations, 'Daftar penyesuaian nilai berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/revaluations',
        summary: 'Create amount adjustment (langsung apply, tanpa approval)',
        security: [['bearerAuth' => []]],
        tags: ['Amount Adjustment'],
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
            new OA\Response(response: 201, description: 'Penyesuaian nilai berhasil, harga pokok diperbarui.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreStockRevaluationRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $revaluation = $this->revaluationService->create($data);

            return $this->successResponse($revaluation, 'Penyesuaian nilai berhasil, harga pokok diperbarui.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/revaluations/{id}',
        summary: 'Get amount adjustment detail',
        security: [['bearerAuth' => []]],
        tags: ['Amount Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail penyesuaian nilai berhasil diambil.'),
            new OA\Response(response: 404, description: 'Tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $revaluation = $this->revaluationService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Dokumen penyesuaian nilai tidak ditemukan.', 404);
        }

        if (!$revaluation) {
            return $this->errorResponse('Dokumen penyesuaian nilai tidak ditemukan.', 404);
        }

        return $this->successResponse($revaluation, 'Detail penyesuaian nilai berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/revaluations/{id}/cancel',
        summary: 'Cancel an amount adjustment (rollback avg_cost)',
        security: [['bearerAuth' => []]],
        tags: ['Amount Adjustment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Penyesuaian nilai berhasil dibatalkan.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $revaluation = $this->revaluationService->cancel($id);

            return $this->successResponse($revaluation, 'Penyesuaian nilai berhasil dibatalkan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
