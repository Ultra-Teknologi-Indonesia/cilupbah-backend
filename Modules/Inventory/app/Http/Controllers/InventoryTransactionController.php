<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Requests\PutawayStockRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inventory Transactions', description: 'API Endpoints for Inventory Transactions')]
#[OA\Schema(
    schema: 'AdjustStockRequest',
    required: ['item_id', 'location_id', 'qty', 'created_by'],
    type: 'object',
    properties: [
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'bin_id', type: 'string', example: '019ea2afad2170bbb0e9956fea210bfc', nullable: true),
        new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
        new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true),
        new OA\Property(property: 'expired_date', type: 'string', format: 'date', example: '2026-12-31', nullable: true),
        new OA\Property(property: 'qty', type: 'integer', example: 10),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin')
    ]
)]
#[OA\Schema(
    schema: 'PutawayStockRequest',
    required: ['item_id', 'location_id', 'source_bin_id', 'destination_bin_id', 'qty', 'created_by'],
    type: 'object',
    properties: [
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'source_bin_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'destination_bin_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
        new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true),
        new OA\Property(property: 'expired_date', type: 'string', format: 'date', example: '2026-12-31', nullable: true),
        new OA\Property(property: 'qty', type: 'integer', example: 50),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin')
    ]
)]
#[OA\Schema(
    schema: 'TransferStockRequest',
    required: ['item_id', 'source_location_id', 'destination_location_id', 'qty', 'created_by'],
    type: 'object',
    properties: [
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'source_location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'source_bin_id', type: 'string', example: '019ea2afad2170bbb0e9956fea210bfc', nullable: true),
        new OA\Property(property: 'destination_location_id', type: 'string', example: '019ea2afad2170bbb0e9956fea885852'),
        new OA\Property(property: 'destination_bin_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7', nullable: true),
        new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
        new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true),
        new OA\Property(property: 'expired_date', type: 'string', format: 'date', example: '2026-12-31', nullable: true),
        new OA\Property(property: 'qty', type: 'integer', example: 20),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin')
    ]
)]
class InventoryTransactionController extends Controller
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    #[OA\Post(
        path: '/api/v1/inventory/adjustments',
        summary: 'Adjust inventory stock',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AdjustStockRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stock adjustment berhasil.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Stock adjustment berhasil.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->adjust($request->validated());

            return $this->successResponse($inventory, 'Stock adjustment berhasil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfers',
        summary: 'Transfer inventory stock (Transfer Out)',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/TransferStockRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transfer Out berhasil dibuat.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Transfer Out berhasil dibuat, barang sedang dalam perjalanan (Transit).')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function transferOut(\Modules\Inventory\Http\Requests\TransferOutRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->transferOut($request->validated());
            return $this->successResponse($result, 'Transfer Out berhasil dibuat sebagai draft.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function approveTransfer(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'approved_by' => 'required|string',
                'assigned_to' => 'required|string',
            ]);

            $result = $this->inventoryService->approveTransfer($id, $validated);
            return $this->successResponse($result, 'Transfer berhasil di-approve dan pekerja telah di-assign.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function cancelTransfer(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'cancelled_by' => 'required|string',
                'cancel_reason' => 'nullable|string',
            ]);

            $result = $this->inventoryService->cancelTransfer($id, $validated);
            return $this->successResponse($result, 'Transfer berhasil dibatalkan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function shipTransfer(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shipped_by' => 'nullable|string',
            ]);

            $result = $this->inventoryService->shipTransfer($id, $validated);
            return $this->successResponse($result, 'Transfer berhasil dikirim, barang dalam perjalanan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfers/{id}/in',
        summary: 'Transfer In inventory stock',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the transfer', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                type: 'object',
                properties: [
                    new OA\Property(property: 'received_by', type: 'string', example: 'admin')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Transfer In berhasil.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Transfer In berhasil, stok telah masuk ke gudang tujuan.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function transferIn(\Modules\Inventory\Http\Requests\TransferInRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->transferIn($id, $request->validated());
            return $this->successResponse($result, 'Transfer In berhasil, stok telah masuk ke gudang tujuan.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfers/transit',
        summary: 'Get list of transit transfers',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar barang dalam perjalanan (Transit).'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function transitList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $transfers = $this->inventoryService->getTransfersPaginated(['status' => 'IN_TRANSIT'], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar barang dalam perjalanan (Transit).');
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfers/{id}',
        summary: 'Get transfer details',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail transfer.'),
            new OA\Response(response: 404, description: 'Transfer tidak ditemukan.')
        ]
    )]
    public function transferShow(string $id): JsonResponse
    {
        try {
            $transfer = $this->inventoryService->getTransferById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Transfer tidak ditemukan', 404);
        }

        if (! $transfer) {
            return $this->errorResponse('Transfer tidak ditemukan', 404);
        }

        return $this->successResponse($transfer, 'Detail transfer berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfers',
        summary: 'Get all transfers list',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar semua dokumen transfer.'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function transfersList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $transfers = $this->inventoryService->getTransfersPaginated([], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar semua dokumen transfer.');
    }

    #[OA\Delete(
        path: '/api/v1/inventory/transfers/{id}',
        summary: 'Delete a transfer document',
        description: 'Only DRAFT transfers can be deleted.',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Transfer berhasil dihapus.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function transferDestroy(string $id): JsonResponse
    {
        try {
            $this->inventoryService->deleteTransfer($id);
            return $this->successResponse(null, 'Transfer berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfers/out-finished',
        summary: 'Get finished (received) transfers',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar transfer yang sudah selesai diterima.'),
        ]
    )]
    public function finishedList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $transfers = $this->inventoryService->getTransfersPaginated(['status' => 'RECEIVED'], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar transfer yang sudah selesai diterima.');
    }

    public function draftList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $transfers = $this->inventoryService->getTransfersPaginated(['status' => 'DRAFT'], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar transfer draft (menunggu approval).');
    }

    public function approvedList(\Illuminate\Http\Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $transfers = $this->inventoryService->getTransfersPaginated(['status' => 'APPROVED'], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar transfer approved (siap dikirim).');
    }

    public function transfersIn(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'bail|required|uuid|exists:locations,id',
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $transfers = $this->inventoryService->getTransfersPaginated([
            'status' => 'IN_TRANSIT',
            'destination_location_id' => $validated['location_id'],
        ], (int) $request->query('limit', 10));

        return $this->successPaginatedResponse($transfers, 'Daftar transfer masuk (menunggu diterima).');
    }

    public function transfersOut(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'bail|required|uuid|exists:locations,id',
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $transfers = $this->inventoryService->getTransfersPaginated([
            'status' => 'IN_TRANSIT',
            'source_location_id' => $validated['location_id'],
        ], (int) $request->query('limit', 10));

        return $this->successPaginatedResponse($transfers, 'Daftar transfer keluar (dalam perjalanan).');
    }

    #[OA\Post(
        path: '/api/v1/inventory/putaway',
        summary: 'Putaway inventory stock',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/PutawayStockRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Putaway berhasil.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Putaway berhasil.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function putaway(PutawayStockRequest $request): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->putaway($request->validated());

            return $this->successResponse($inventory, 'Putaway berhasil.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfer/mark-printed',
        summary: 'Mark a transfer document as printed',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['transfer_id'],
            properties: [
                new OA\Property(property: 'transfer_id', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Transfer ditandai sudah dicetak.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function markTransferPrinted(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $request->validate(['transfer_id' => 'required|string']);
            $printedBy = $request->user()->name ?? $request->user()->email;
            $transfer = $this->inventoryService->markTransferPrinted($request->input('transfer_id'), $printedBy);

            return $this->successResponse($transfer, 'Transfer ditandai sudah dicetak.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfer/delivery',
        summary: 'Get transfer delivery report (surat jalan)',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'transfer_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Data surat jalan transfer berhasil diambil.'),
            new OA\Response(response: 404, description: 'Transfer tidak ditemukan.'),
        ]
    )]
    public function transferDelivery(\Illuminate\Http\Request $request): JsonResponse
    {
        $transferId = $request->query('transfer_id');

        if (!$transferId) {
            return $this->errorResponse('Parameter transfer_id wajib diisi.', 422);
        }

        try {
            $transfer = $this->inventoryService->getTransferById($transferId);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Transfer tidak ditemukan.', 404);
        }

        if (!$transfer) {
            return $this->errorResponse('Transfer tidak ditemukan.', 404);
        }

        return $this->successResponse($transfer, 'Data surat jalan transfer berhasil diambil.');
    }
}
