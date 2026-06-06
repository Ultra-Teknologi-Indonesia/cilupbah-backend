<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory', description: 'API Endpoints for Inventory')]
#[OA\Schema(
    schema: 'InventoryStock',
    title: 'Inventory Stock Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'item_id', type: 'integer', example: 10),
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'bin_id', type: 'string', format: 'uuid', example: '550e8400-e29b-41d4-a716-446655440000', nullable: true),
        new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
        new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true),
        new OA\Property(property: 'expired_date', type: 'string', format: 'date', example: '2026-12-31', nullable: true),
        new OA\Property(property: 'qty', type: 'integer', example: 100),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
class InventoryController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/stocks',
        summary: 'Get list of inventory stocks',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStock')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar stok berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $stocks = $this->inventoryService->getAllPaginated($limit);

        return $this->successPaginatedResponse($stocks, 'Daftar stok berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stocks/{itemId}',
        summary: 'Get stock details by item ID',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'itemId', in: 'path', required: true, description: 'ID of the item', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InventoryStock')),
                        new OA\Property(property: 'message', type: 'string', example: 'Detail stok per item berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function show(int $itemId): JsonResponse
    {
        $stocks = $this->inventoryService->getStockByItem($itemId);

        return $this->successResponse($stocks, 'Detail stok per item berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inventory/movements',
        summary: 'Get stock movements history',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'message', type: 'string', example: 'Riwayat pergerakan stok berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function movements(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $movements = $this->inventoryService->getHistoryPaginated($limit);

        return $this->successPaginatedResponse($movements, 'Riwayat pergerakan stok berhasil diambil');
    }
}
