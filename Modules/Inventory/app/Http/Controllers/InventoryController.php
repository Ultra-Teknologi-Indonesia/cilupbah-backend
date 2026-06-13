<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Http\Requests\SplitItemRequest;
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
        protected InventoryService $inventoryService,
        protected InventoryRepository $inventoryRepository,
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
        $request->validate([
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $limit = (int) $request->query('limit', 10);
        $movements = $this->inventoryService->getHistoryPaginated($limit);

        return $this->successPaginatedResponse(
            \Modules\Inventory\Http\Resources\InventoryMovementResource::collection($movements),
            'Riwayat pergerakan stok berhasil diambil'
        );
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

    #[OA\Post(
        path: '/api/v1/inventory/items/to-adjust',
        summary: 'Get cost and stock by item IDs for adjustment',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['item_ids'],
            properties: [
                new OA\Property(property: 'item_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Data stok untuk penyesuaian berhasil diambil.'),
        ]
    )]
    public function toAdjust(Request $request): JsonResponse
    {
        $request->validate(['item_ids' => 'required|array', 'item_ids.*' => 'string']);

        $stocks = $this->inventoryRepository->getStockByItemIds($request->input('item_ids'));

        return $this->successResponse($stocks, 'Data stok untuk penyesuaian berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/out-of-stock-in-order',
        summary: 'Get products that are out of stock but have pending orders',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar produk habis stok dalam order berhasil diambil.'),
        ]
    )]
    public function outOfStockInOrder(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->inventoryRepository->getOutOfStockInOrder($limit);

        return $this->successPaginatedResponse($items, 'Daftar produk habis stok dalam order berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/{id}/batch-number',
        summary: 'Get batch and serial numbers for an item',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar batch number berhasil diambil.'),
        ]
    )]
    public function batchNumbers(string $id): JsonResponse
    {
        $batches = $this->inventoryRepository->getBatchNumbers($id);

        return $this->successResponse($batches, 'Daftar batch number berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/to-sell/{locationId}',
        summary: 'Get items available to sell at a location',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item yang bisa dijual berhasil diambil.'),
        ]
    )]
    public function toSell(Request $request, string $locationId): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->inventoryRepository->getAvailableToSell($locationId, $limit);

        return $this->successPaginatedResponse($items, 'Daftar item yang bisa dijual berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/to-sales-return',
        summary: 'Get items eligible for sales return',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item retur penjualan berhasil diambil.'),
        ]
    )]
    public function toSalesReturn(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $items = DB::table('sales_return_items')
            ->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_return_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->whereIn('sales_returns.status', ['PENDING', 'APPROVED'])
            ->select(
                'sales_return_items.id',
                'sales_return_items.item_id',
                'sales_return_items.qty',
                'sales_return_items.condition',
                'sales_returns.return_number',
                'sales_returns.status as return_status',
                'sales_returns.order_id',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->orderByDesc('sales_returns.created_at')
            ->paginate($limit);

        return $this->successPaginatedResponse($items, 'Daftar item retur penjualan berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/need-restock',
        summary: 'Get products that need restocking (available < min_stock)',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar produk yang perlu restock berhasil diambil.'),
        ]
    )]
    public function needRestock(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);

        $items = DB::table('product_variants')
            ->where('product_variants.min_stock', '>', 0)
            ->leftJoin('inventories', 'inventories.item_id', '=', 'product_variants.id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->groupBy('product_variants.id', 'product_variants.sku', 'product_variants.min_stock', 'products.name')
            ->havingRaw('COALESCE(SUM(inventories.available), 0) < product_variants.min_stock')
            ->select(
                'product_variants.id as item_id',
                'product_variants.sku',
                'product_variants.min_stock',
                'products.name as product_name',
                DB::raw('COALESCE(SUM(inventories.on_hand), 0) as total_on_hand'),
                DB::raw('COALESCE(SUM(inventories.available), 0) as total_available'),
                DB::raw('product_variants.min_stock - COALESCE(SUM(inventories.available), 0) as qty_to_restock'),
            )
            ->orderByDesc('qty_to_restock')
            ->paginate($limit);

        return $this->successPaginatedResponse($items, 'Daftar produk yang perlu restock berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/items/split-item',
        summary: 'Split item into smaller units',
        description: 'Deduct qty from source item, add split_into_qty to target item.',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['source_item_id', 'target_item_id', 'location_id', 'qty_to_split', 'split_into_qty'],
            properties: [
                new OA\Property(property: 'source_item_id', type: 'string'),
                new OA\Property(property: 'target_item_id', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'bin_id', type: 'string', nullable: true),
                new OA\Property(property: 'qty_to_split', type: 'integer', example: 1),
                new OA\Property(property: 'split_into_qty', type: 'integer', example: 10),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Item berhasil di-split.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function splitItem(SplitItemRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->name ?? $request->user()->email;

            $result = $this->inventoryService->splitItem($data);

            return $this->successResponse($result, 'Item berhasil di-split.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/by-bill/{docId}',
        summary: 'Get items by purchase bill',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'docId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item purchase bill berhasil diambil.'),
        ]
    )]
    public function itemsByBill(string $docId): JsonResponse
    {
        $items = DB::table('purchase_bill_items')
            ->where('purchase_bill_items.purchase_bill_id', $docId)
            ->join('product_variants', 'product_variants.id', '=', 'purchase_bill_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'purchase_bill_items.*',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->get();

        return $this->successResponse($items, 'Daftar item purchase bill berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/by-invoice/{invoiceId}',
        summary: 'Get items by sales invoice',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'invoiceId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item sales invoice berhasil diambil.'),
        ]
    )]
    public function itemsByInvoice(string $invoiceId): JsonResponse
    {
        $items = DB::table('sales_invoice_items')
            ->where('sales_invoice_items.sales_invoice_id', $invoiceId)
            ->join('product_variants', 'product_variants.id', '=', 'sales_invoice_items.item_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select(
                'sales_invoice_items.*',
                'product_variants.sku',
                'products.name as product_name',
            )
            ->get();

        return $this->successResponse($items, 'Daftar item sales invoice berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/items/all-stocks',
        summary: 'Get product stocks by multiple variant IDs',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string'))],
        )),
        responses: [new OA\Response(response: 200, description: 'Stok produk berhasil diambil.')]
    )]
    public function allStocksByIds(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'string']);

        $stocks = Inventory::select('item_id', 'location_id',
                DB::raw('SUM(on_hand) as total_on_hand'),
                DB::raw('SUM(reserved) as total_reserved'),
                DB::raw('SUM(available) as total_available'))
            ->whereIn('item_id', $request->input('ids'))
            ->groupBy('item_id', 'location_id')
            ->with(['product:id,sku,product_id', 'location:id,location_name,location_code'])
            ->get();

        return $this->successResponse($stocks, 'Stok produk berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/items/by-sku/{sku}',
        summary: 'Get product by SKU',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [new OA\Parameter(name: 'sku', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Produk ditemukan.'),
            new OA\Response(response: 404, description: 'SKU tidak ditemukan.'),
        ]
    )]
    public function bySku(string $sku): JsonResponse
    {
        $variant = \Modules\Product\Models\ProductVariant::where('sku', $sku)
            ->with(['product:id,name,sku,category_id,brand_id,status,is_bundle', 'product.category:id,name', 'product.brand:id,name', 'inventories'])
            ->first();

        if (! $variant) {
            return $this->errorResponse('SKU tidak ditemukan.', 404);
        }

        return $this->successResponse($variant, 'Produk ditemukan.');
    }

    #[OA\Delete(
        path: '/api/v1/inventory/items/item-variant',
        summary: 'Delete item variant',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [new OA\Property(property: 'id', type: 'string'), new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string'))],
        )),
        responses: [new OA\Response(response: 200, description: 'Varian berhasil dihapus.')]
    )]
    public function deleteVariant(Request $request): JsonResponse
    {
        $ids = (array) ($request->input('id') ?? $request->input('ids', []));

        if (empty($ids)) {
            return $this->errorResponse('id or ids required.', 422);
        }

        $hasStock = Inventory::whereIn('item_id', $ids)
            ->where('on_hand', '>', 0)
            ->exists();

        if ($hasStock) {
            return $this->errorResponse('Tidak bisa menghapus varian yang masih punya stok.', 422);
        }

        $deleted = \Modules\Product\Models\ProductVariant::whereIn('id', $ids)->delete();

        return $this->successResponse(['deleted' => $deleted], 'Varian berhasil dihapus.');
    }
}
