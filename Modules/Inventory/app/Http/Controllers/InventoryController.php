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
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory', description: 'API Endpoints for Inventory')]
#[OA\Schema(
    schema: 'InventoryStock',
    title: 'Inventory Stock Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad2170bbb0e9956fea210bfc'),
        new OA\Property(property: 'bin_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e', nullable: true),
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
            new OA\Parameter(name: 'itemId', in: 'path', required: true, description: 'ID of the item', schema: new OA\Schema(type: 'string'))
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
    public function show(string $itemId): JsonResponse
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

    #[OA\Get(
        path: '/api/v1/inventory/items/to-stock',
        summary: 'Get list of items available to stock',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item to-stock berhasil diambil.'),
        ]
    )]
    public function itemsToStock(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $items = QueryBuilder::for(\Modules\Product\Models\ProductVariant::class)
            ->select('product_variants.id', 'product_variants.sku', 'product_variants.product_id')
            ->with(['product:id,name'])
            ->allowedFilters(
                AllowedFilter::partial('sku'),
            )
            ->allowedSorts('sku', 'created_at')
            ->defaultSort('sku')
            ->paginate($limit);

        return $this->successPaginatedResponse($items, 'Daftar item to-stock berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-products',
        summary: 'Get all stock products grouped by product',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar stok produk berhasil diambil.'),
        ]
    )]
    public function stockProducts(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $stocks = QueryBuilder::for(Inventory::class)
            ->select('item_id', DB::raw('SUM(on_hand) as total_on_hand'), DB::raw('SUM(reserved) as total_reserved'), DB::raw('SUM(available) as total_available'))
            ->groupBy('item_id')
            ->with(['product:id,sku,product_id'])
            ->allowedFilters(
                AllowedFilter::exact('item_id'),
            )
            ->allowedSorts('total_on_hand', 'total_available')
            ->paginate($limit);

        return $this->successPaginatedResponse($stocks, 'Daftar stok produk berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/history',
        summary: 'Get stock movement history with filters',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[item_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[date_from]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'filter[date_to]', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Riwayat stok berhasil diambil.'),
        ]
    )]
    public function history(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $movements = $this->inventoryService->getHistoryPaginated($limit);

        return $this->successPaginatedResponse($movements, 'Riwayat stok berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/by-location/{locationId}',
        summary: 'Get inventory items by location',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stok per lokasi berhasil diambil.'),
        ]
    )]
    public function byLocation(string $locationId): JsonResponse
    {
        $stocks = $this->inventoryService->getStockByLocation($locationId);

        return $this->successResponse($stocks, 'Stok per lokasi berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/purchase-order/items',
        summary: 'Get items in purchase orders that are not fully received',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item PO berhasil diambil.'),
        ]
    )]
    public function purchaseOrderItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $items = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'purchase_order_items.item_id')
            ->whereIn('purchase_orders.status', ['OPEN', 'PARTIAL_RECEIVED'])
            ->select(
                'purchase_order_items.*',
                'purchase_orders.po_number',
                'purchase_orders.status as po_status',
                'product_variants.sku'
            )
            ->orderByDesc('purchase_orders.created_at')
            ->paginate($limit);

        return $this->successPaginatedResponse($items, 'Daftar item PO berhasil diambil.');
    }
}
