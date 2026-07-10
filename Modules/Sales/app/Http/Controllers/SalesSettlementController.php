<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesSettlementService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Settlements', description: 'API Endpoints for Marketplace Settlements')]
class SalesSettlementController extends Controller
{
    public function __construct(
        protected SalesSettlementService $settlementService,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/settlements',
        summary: 'Get list of marketplace settlements',
        security: [['bearerAuth' => []]],
        tags: ['Sales Settlements'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $settlements = $this->settlementService->getAllPaginated($limit);

        return $this->successPaginatedResponse($settlements, 'Daftar settlement berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/settlements/{id}',
        summary: 'Get settlement details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Settlement tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $settlement = $this->settlementService->getById($id);

        if (! $settlement) {
            return $this->errorResponse('Settlement tidak ditemukan', 404);
        }

        return $this->successResponse($settlement, 'Detail settlement berhasil diambil');
    }
}
