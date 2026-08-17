<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\ReservedStockService;
use Modules\Inventory\Http\Requests\StoreReservedStockRequest;
use Modules\Inventory\Http\Resources\ReservedStockResource;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Reserved Stock', description: 'API Endpoints for Document-based Reserved Stock')]
class ReservedStockController extends Controller
{
    public function __construct(
        protected ReservedStockService $reservedStockService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/reserved-stocks',
        summary: 'Get list of reserved stock documents',
        security: [['bearerAuth' => []]],
        tags: ['Reserved Stock'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ACTIVE', 'EXPIRED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, description: 'Search by reserved_stock_no', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar dokumen reserved stock berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $reservedStocks = $this->reservedStockService->getAllPaginated($limit);

        $reservedStocks->getCollection()->transform(
            fn ($m) => (new ReservedStockResource($m))->resolve($request),
        );

        return $this->successPaginatedResponse($reservedStocks, 'Daftar dokumen reserved stock berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/reserved-stocks/{id}',
        summary: 'Get reserved stock document detail',
        security: [['bearerAuth' => []]],
        tags: ['Reserved Stock'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail reserved stock berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $reservedStock = $this->reservedStockService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Dokumen reserved stock tidak ditemukan.', 404);
        }

        if (!$reservedStock) {
            return $this->errorResponse('Dokumen reserved stock tidak ditemukan.', 404);
        }

        return $this->successResponse(new ReservedStockResource($reservedStock), 'Detail reserved stock berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/reserved-stocks',
        summary: 'Create a new reserved stock document',
        security: [['bearerAuth' => []]],
        tags: ['Reserved Stock'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['start_date', 'end_date', 'location_id', 'items'],
            properties: [
                new OA\Property(property: 'start_date', type: 'string', format: 'date-time'),
                new OA\Property(property: 'end_date', type: 'string', format: 'date-time'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'is_active', type: 'boolean'),
                new OA\Property(property: 'notes', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                        new OA\Property(property: 'qty', type: 'integer'),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 202, description: 'Dokumen reserved stock berhasil dibuat, reservasi sedang diproses.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreReservedStockRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $reservedStock = $this->reservedStockService->create($data);

            return $this->successResponse(new ReservedStockResource($reservedStock), 'Dokumen reserved stock berhasil dibuat, reservasi sedang diproses.', 202);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/reserved-stocks/{id}/cancel',
        summary: 'Cancel a reserved stock document',
        security: [['bearerAuth' => []]],
        tags: ['Reserved Stock'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Dokumen reserved stock berhasil di-cancel, reservasi di-rollback.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $reservedStock = $this->reservedStockService->cancel($id);

            return $this->successResponse(new ReservedStockResource($reservedStock), 'Dokumen reserved stock berhasil di-cancel, reservasi di-rollback.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal membatalkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
