<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Repositories\InventoryRepository;
use Modules\Inventory\Http\Requests\SplitItemRequest;
use Modules\Inventory\Http\Resources\StockItemResource;
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
        path: '/api/v1/inventory',
        summary: 'Get stock items with channels, locations, and per-location breakdown',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Search by SKU or product name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[product_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort by: product_variants.sku, product_variants.created_at', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar stok inventory berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function stockItems(Request $request): JsonResponse
    {
        try {
            $data = $this->inventoryService->getStockItems();

            return $this->successResponse(
                StockItemResource::collection($data->items()),
                'Daftar stok inventory berhasil diambil.',
                200,
                [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'channels' => $this->inventoryService->getActiveChannels(),
                    'locations' => $this->inventoryService->getActiveLocations(),
                ]
            );
        } catch (\Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal mengambil data stok.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function stockItemShow(string $itemId): JsonResponse
    {
        try {
            $variant = $this->inventoryService->getStockItemDetail($itemId);

            return $this->successResponse(
                new StockItemResource($variant),
                'Detail stok item berhasil diambil.'
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Item tidak ditemukan.', 404);
        } catch (\Throwable $e) {
            report($e);

            return $this->errorResponse(
                'Gagal mengambil detail stok.',
                500,
                ['detail' => $e->getMessage()],
                'Terjadi kesalahan',
            );
        }
    }

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

        return $this->successResponse(
            \Modules\Inventory\Http\Resources\InventoryStockResource::collection($stocks),
            'Detail stok per item berhasil diambil'
        );
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

        $items = $this->inventoryRepository->getItemsToStock($limit);

        return $this->successPaginatedResponse($items, 'Daftar item to-stock berhasil diambil.');
    }

    public function itemsOnStock(Request $request): JsonResponse
    {

        $limit = $request->query('limit', 200);

        $items = $this->inventoryRepository->getItemsOnStock($limit);

        return $this->successPaginatedResponse($items, 'Daftar item on-stock berhasil diambil.');
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

        $stocks = $this->inventoryRepository->getStockProducts($limit);

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

        return $this->successPaginatedResponse(
            \Modules\Inventory\Http\Resources\InventoryMovementResource::collection($movements),
            'Riwayat stok berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/inventory/movement-filters',
        summary: 'Get available filter options (source groups + direction) for movement history',
        security: [['bearerAuth' => []]],
        tags: ['Inventory'],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function movementFilters(): JsonResponse
    {
        return $this->successResponse(
            $this->inventoryService->getMovementFilterOptions(),
            'Opsi filter kronologi stok berhasil diambil.'
        );
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

        $items = $this->inventoryRepository->getPurchaseOrderItems($limit);

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

        $items = $this->inventoryRepository->getSalesReturnItems($limit);

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

        $service = app(\Modules\Inventory\Services\MonitorStockService::class);
        $perPage = (int) ($request->query('per_page') ?? $request->query('limit') ?? 10);
        $data = $service->lowStock($service->filtersFrom($request->query()), $perPage);

        return $this->successPaginatedResponse(
            \Modules\Inventory\Http\Resources\MonitorStockResource::collection($data),
            'Daftar produk yang perlu restock berhasil diambil.'
        );
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
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
        $items = $this->inventoryRepository->getItemsByBill($docId);

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
        $items = $this->inventoryRepository->getItemsByInvoice($invoiceId);

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

        $stocks = $this->inventoryRepository->getAggregatedStocksByIds($request->input('ids'));

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
    public function bySku(string $sku, Request $request): JsonResponse
    {
        $variant = $this->inventoryService->findVariantForSku($sku);

        if (! $variant) {
            return $this->errorResponse('SKU tidak ditemukan.', 404);
        }

        $locationId = $request->query('location_id');

        $summary = $this->inventoryService->buildSkuStockSummary($variant, $locationId);

        if ($locationId && $request->boolean('require_stock') && empty($summary['available_bins'])) {
            return $this->errorResponse('SKU tidak punya stok di gudang ini.', 404);
        }

        return $this->successResponse($summary, 'Produk ditemukan.');
    }

    public function byBinCode(string $binCode): JsonResponse
    {
        $items = $this->inventoryService->getBinStockItems($binCode);

        if ($items === null) {
            return $this->errorResponse('Rak tidak ditemukan.', 404);
        }

        return $this->successResponse($items, 'Stok rak ditemukan.');
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

        try {
            $deleted = $this->inventoryService->deleteVariants($ids);
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(['deleted' => $deleted], 'Varian berhasil dihapus.');
    }

    public function stockedItems(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        if (! $locationId) {
            return $this->errorResponse('Parameter location_id wajib.', 422);
        }

        $search = trim((string) $request->query('search', ''));
        $perPage = (int) $request->query('per_page', 20);
        if ($perPage <= 0 || $perPage > 200) {
            $perPage = 20;
        }

        $paginated = $this->inventoryService->getStockedItems($locationId, $search, $perPage);

        return $this->successPaginatedResponse($paginated, 'Daftar produk berstok diambil.');
    }
}
