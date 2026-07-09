<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\StockOpnameService;
use Modules\Inventory\Http\Requests\StoreStockOpnameRequest;
use Modules\Inventory\Http\Requests\CountStockOpnameItemRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Stock Opname', description: 'API Endpoints for Stock Opname (Physical Inventory Count)')]
#[OA\Schema(
    schema: 'StockOpname',
    title: 'Stock Opname Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'opname_no', type: 'string', example: 'OP-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'filter_zone_id', type: 'string', nullable: true, example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'filter_floor_codes', type: 'array', nullable: true, items: new OA\Items(type: 'string', example: 'FL1')),
        new OA\Property(property: 'filter_row_codes', type: 'array', nullable: true, items: new OA\Items(type: 'string', example: 'RW1')),
        new OA\Property(property: 'filter_column_codes', type: 'array', nullable: true, items: new OA\Items(type: 'string', example: 'C1')),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'FINALIZED', 'CANCELLED'], example: 'DRAFT'),
        new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Opname bulanan zona A'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin@warehouse.com'),
        new OA\Property(property: 'process_by', type: 'string', nullable: true, example: 'staff@warehouse.com'),
        new OA\Property(property: 'finalized_by', type: 'string', nullable: true),
        new OA\Property(property: 'finalized_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'printed_by', type: 'string', nullable: true),
        new OA\Property(property: 'printed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'location', type: 'object', nullable: true),
        new OA\Property(property: 'zone', type: 'object', nullable: true),
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/StockOpnameItem')),
    ]
)]
#[OA\Schema(
    schema: 'StockOpnameItem',
    title: 'Stock Opname Item Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'stock_opname_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'bin_id', type: 'string', nullable: true, example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'batch_no', type: 'string', nullable: true),
        new OA\Property(property: 'serial_no', type: 'string', nullable: true),
        new OA\Property(property: 'expired_date', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'qty_system', type: 'integer', example: 100),
        new OA\Property(property: 'qty_actual', type: 'integer', nullable: true, example: 98),
        new OA\Property(property: 'qty_difference', type: 'integer', nullable: true, example: -2),
        new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Barang rusak'),
        new OA\Property(property: 'counted_by', type: 'string', nullable: true, example: 'staff@warehouse.com'),
        new OA\Property(property: 'counted_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'product', type: 'object', nullable: true),
        new OA\Property(property: 'bin', type: 'object', nullable: true),
    ]
)]
class StockOpnameController extends Controller
{
    public function __construct(
        protected StockOpnameService $opnameService
    ) {}

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname',
        summary: 'Get list of stock opname documents',
        description: 'Retrieve all stock opname with filter by status, location, and search by opname_no.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'FINALIZED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, description: 'Search by opname_no', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort by: created_at, opname_no, finalized_at (prefix with - for desc)', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar stock opname berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $opnames = $this->opnameService->getAllPaginated($limit);

        return $this->successPaginatedResponse($opnames, 'Daftar stock opname berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/{id}',
        summary: 'Get stock opname detail with items',
        description: 'Retrieve real-time stock opname detail including all counted items.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail stock opname berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $opname = $this->opnameService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Dokumen stock opname tidak ditemukan.', 404);
        }

        if (!$opname) {
            return $this->errorResponse('Dokumen stock opname tidak ditemukan.', 404);
        }

        return $this->successResponse($opname, 'Detail stock opname berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname',
        summary: 'Create a new stock opname',
        description: 'Create stock opname document. System will auto-populate items based on existing inventory in the selected bins/zone/location. Filter by zone, floor, row, column to narrow down scope.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id'],
            properties: [
                new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
                new OA\Property(property: 'filter_zone_id', type: 'string', nullable: true, description: 'Filter by specific zone'),
                new OA\Property(property: 'filter_floor_codes', type: 'array', nullable: true, description: 'Filter by floor codes', items: new OA\Items(type: 'string', example: 'FL1')),
                new OA\Property(property: 'filter_row_codes', type: 'array', nullable: true, description: 'Filter by row codes', items: new OA\Items(type: 'string', example: 'RW1')),
                new OA\Property(property: 'filter_column_codes', type: 'array', nullable: true, description: 'Filter by column codes', items: new OA\Items(type: 'string', example: 'C1')),
                new OA\Property(property: 'notes', type: 'string', nullable: true, example: 'Opname bulanan zona A'),
                new OA\Property(property: 'process_by', type: 'string', nullable: true, description: 'Staff yang akan melakukan counting', example: 'staff@warehouse.com'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Stock opname berhasil dibuat.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreStockOpnameRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = $request->user()->id;

            $opname = $this->opnameService->create($data);

            return $this->successResponse($opname, 'Stock opname berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname/{id}/start',
        summary: 'Start stock opname process',
        description: 'Change status from DRAFT to IN_PROGRESS. Assign process_by staff.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock opname mulai diproses.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function start(Request $request, string $id): JsonResponse
    {
        try {
            $processBy = $request->user()->name ?? $request->user()->email;
            $opname = $this->opnameService->startProcess($id, $processBy);

            return $this->successResponse($opname, 'Stock opname mulai diproses.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/{id}/items',
        summary: 'Get paginated items of a stock opname',
        description: 'Get list of items to be counted. Filter by bin_id, item_id, counted status, or items with difference.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[bin_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[item_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[counted]', in: 'query', required: false, description: 'true = sudah dihitung, false = belum', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
            new OA\Parameter(name: 'filter[has_difference]', in: 'query', required: false, description: 'true = hanya item yang ada selisih', schema: new OA\Schema(type: 'string', enum: ['true', 'false'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item opname berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function items(Request $request, string $id): JsonResponse
    {
        $opname = $this->opnameService->getById($id);

        if (!$opname) {
            return $this->errorResponse('Dokumen stock opname tidak ditemukan.', 404);
        }

        $limit = $request->query('limit', 10);
        $items = $this->opnameService->getItemsPaginated($id, $limit);

        return $this->successPaginatedResponse($items, 'Daftar item opname berhasil diambil.');
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname/{id}/items/{itemId}/count',
        summary: 'Count (input actual qty) for a specific opname item',
        description: 'Input the actual physical count for a specific item. System will auto-calculate qty_difference.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Stock Opname ID', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, description: 'Stock Opname Item ID', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['qty_actual'],
            properties: [
                new OA\Property(property: 'qty_actual', type: 'integer', example: 98, description: 'Jumlah fisik aktual yang dihitung'),
                new OA\Property(property: 'reason', type: 'string', nullable: true, example: 'Barang rusak', description: 'Alasan jika ada selisih'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Item berhasil dihitung.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function countItem(CountStockOpnameItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['counted_by'] = $request->user()->name ?? $request->user()->email;

            $opname = $this->opnameService->countItem($id, $itemId, $data);

            return $this->successResponse($opname, 'Item berhasil dihitung.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname/{id}/finalize',
        summary: 'Finalize stock opname and push adjustments',
        description: 'Set stock opname as done. All items must be counted. System will dispatch a job to adjust inventory based on differences.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Stock opname di-finalize, penyesuaian stok sedang diproses.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function finalize(Request $request, string $id): JsonResponse
    {
        try {
            $finalizedBy = $request->user()->name ?? $request->user()->email;
            $opname = $this->opnameService->finalize($id, $finalizedBy);

            return $this->successResponse($opname, 'Stock opname di-finalize, penyesuaian stok sedang diproses.', 202);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname/{id}/cancel',
        summary: 'Cancel a stock opname',
        description: 'Cancel stock opname. Cannot cancel if already finalized.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock opname berhasil di-cancel.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $opname = $this->opnameService->cancel($id);

            return $this->successResponse($opname, 'Stock opname berhasil di-cancel.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/inventory/stock-opname/{id}',
        summary: 'Delete a stock opname (soft delete)',
        description: 'Only DRAFT documents can be deleted.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock opname berhasil dihapus.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->opnameService->delete($id);

            return $this->successResponse(null, 'Stock opname berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/stock-opname/{id}/mark-printed',
        summary: 'Mark stock opname as printed',
        description: 'Record that the opname sheet has been printed for physical counting.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Stock opname ditandai sudah dicetak.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function markPrinted(Request $request, string $id): JsonResponse
    {
        try {
            $printedBy = $request->user()->name ?? $request->user()->email;
            $opname = $this->opnameService->markPrinted($id, $printedBy);

            return $this->successResponse($opname, 'Stock opname ditandai sudah dicetak.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/{id}/items/filtered',
        summary: 'Get opname items filtered by rack location',
        description: 'Filter opname items by floor_code, row_code, column_code, or bin_id.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'floor_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'row_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'column_code', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'bin_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item opname terfilter berhasil diambil.'),
            new OA\Response(response: 404, description: 'Dokumen tidak ditemukan.'),
        ]
    )]
    public function filteredItems(Request $request, string $id): JsonResponse
    {
        $opname = $this->opnameService->getById($id);

        if (!$opname) {
            return $this->errorResponse('Dokumen stock opname tidak ditemukan.', 404);
        }

        $limit = $request->query('limit', 10);
        $rackFilters = $request->only(['floor_code', 'row_code', 'column_code', 'bin_id']);
        $items = $this->opnameService->getItemsFilteredByRack($id, $rackFilters, $limit);

        return $this->successPaginatedResponse($items, 'Daftar item opname terfilter berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/bins',
        summary: 'Get all bins by location for opname scope selection',
        description: 'Retrieve all bin locations for a given location. Used to select opname scope.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar bin berhasil diambil.'),
        ]
    )]
    public function bins(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        $zoneId = $request->query('zone_id');

        if (!$locationId) {
            return $this->errorResponse('Parameter location_id wajib diisi.', 422);
        }

        $bins = $this->opnameService->getBins($locationId, $zoneId);

        return $this->successResponse($bins, 'Daftar bin berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/floors',
        summary: 'Get distinct floor codes by location',
        description: 'Retrieve available floor codes for rack selection. Used to progressively filter opname scope.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar floor berhasil diambil.'),
        ]
    )]
    public function floors(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        $zoneId = $request->query('zone_id');

        if (!$locationId) {
            return $this->errorResponse('Parameter location_id wajib diisi.', 422);
        }

        $floors = $this->opnameService->getFloors($locationId, $zoneId);

        return $this->successResponse($floors, 'Daftar floor berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/rows',
        summary: 'Get distinct row codes by location and floor',
        description: 'Retrieve available row codes for a given floor. Used to progressively filter opname scope.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floor_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar row berhasil diambil.'),
        ]
    )]
    public function rows(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        $floorCode = $request->query('floor_code');
        $zoneId = $request->query('zone_id');

        if (!$locationId || !$floorCode) {
            return $this->errorResponse('Parameter location_id dan floor_code wajib diisi.', 422);
        }

        $rows = $this->opnameService->getRows($locationId, $floorCode, $zoneId);

        return $this->successResponse($rows, 'Daftar row berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/inventory/stock-opname/columns',
        summary: 'Get distinct column codes by location, floor, and row',
        description: 'Retrieve available column codes for a given floor and row. Used to progressively filter opname scope.',
        security: [['bearerAuth' => []]],
        tags: ['Stock Opname'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'floor_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'row_code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'zone_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar column berhasil diambil.'),
        ]
    )]
    public function columns(Request $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        $floorCode = $request->query('floor_code');
        $rowCode = $request->query('row_code');
        $zoneId = $request->query('zone_id');

        if (!$locationId || !$floorCode || !$rowCode) {
            return $this->errorResponse('Parameter location_id, floor_code, dan row_code wajib diisi.', 422);
        }

        $columns = $this->opnameService->getColumns($locationId, $floorCode, $rowCode, $zoneId);

        return $this->successResponse($columns, 'Daftar column berhasil diambil.');
    }
}
