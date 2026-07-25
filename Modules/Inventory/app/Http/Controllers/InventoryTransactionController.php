<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Models\InventoryTransfer;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Http\Resources\InventoryTransferResource;
use Modules\Inventory\Http\Resources\BinTransferResource;
use Modules\Inventory\Http\Resources\BinTransferReceiptResource;
use Modules\Inventory\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Requests\PutawayStockRequest;
use OpenApi\Attributes as OA;
use Throwable;

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
            return $this->errorResponse(
                'Gagal menyesuaikan stok.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer Out berhasil dibuat sebagai draft.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses transfer.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function approveTransfer(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'approved_by' => 'required|string',
                'assigned_to' => 'nullable|string',
            ]);

            $result = $this->inventoryService->approveTransfer($id, $validated);
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer berhasil di-approve.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyetujui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer berhasil dibatalkan.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal membatalkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function shipTransfer(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shipped_by' => 'nullable|string',
            ]);

            $result = $this->inventoryService->shipTransfer($id, $validated);
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer berhasil dikirim, barang dalam perjalanan.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses transfer.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer In berhasil, stok telah masuk ke gudang tujuan.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses transfer.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

        return $this->successResponse(new InventoryTransferResource($transfer), 'Detail transfer berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inventory/transfers/{id}/pdf',
        summary: 'Cetak dokumen Transfer Keluar sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Transfer tidak ditemukan.')
        ]
    )]
    public function transferPdf(string $id)
    {
        $transfer = $this->inventoryService->getTransferById($id);

        if (! $transfer) {
            return $this->errorResponse('Transfer tidak ditemukan', 404);
        }

        try {
            $filename = "{$transfer->transfer_number}.pdf";

            $pdf = Pdf::loadView('inventory::pdf.transfer-out', [
                'transfer' => $transfer,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF transfer.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
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
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfers/bulk/delete',
        summary: 'Hapus/kembalikan banyak transfer sekaligus',
        description: 'Transfer DRAFT/APPROVED dihapus, transfer IN_TRANSIT dikembalikan ke Baru Dibuat (revert).',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Transfer diproses (partial success mungkin terjadi)'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function bulkDeleteTransfer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|string',
        ]);

        $actor = $request->user()?->name ?? $request->user()?->email ?? 'system';

        $result = $this->inventoryService->bulkDeleteOrRevertTransfers($validated['ids'], $actor);

        $messageParts = [];
        if ($result['deleted'] > 0) $messageParts[] = "{$result['deleted']} dihapus";
        if ($result['reverted'] > 0) $messageParts[] = "{$result['reverted']} dikembalikan ke Baru Dibuat";
        if (count($result['failed']) > 0) $messageParts[] = count($result['failed']) . " gagal";

        return $this->successResponse(
            $result,
            $messageParts ? implode(', ', $messageParts) : 'Tidak ada transfer diproses'
        );
    }

    #[OA\Post(
        path: '/api/v1/inventory/transfers/bulk/pdf',
        summary: 'Cetak Surat Jalan untuk banyak transfer sekaligus',
        security: [['bearerAuth' => []]],
        tags: ['Inventory Transactions'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'PDF gabungan Surat Jalan'),
            new OA\Response(response: 404, description: 'Sebagian dokumen tidak ditemukan'),
        ]
    )]
    public function bulkPdfTransfer(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|string',
        ]);

        try {
            $ids = $validated['ids'];
            $transfers = array_values(array_filter(array_map(
                fn (string $id) => $this->inventoryService->getTransferById($id),
                $ids
            )));

            if (count($transfers) !== count($ids)) {
                $foundIds = array_map(fn ($t) => $t->id, $transfers);
                $missing = array_values(array_diff($ids, $foundIds));
                return $this->errorResponse(
                    'Sebagian dokumen tidak ditemukan: ' . implode(', ', $missing),
                    404
                );
            }

            $filename = 'Surat-Jalan-Bulk-' . now()->format('Ymd-His') . '.pdf';

            $pdf = Pdf::loadView('inventory::pdf.transfer-out-bulk', [
                'transfers' => $transfers,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF transfer bulk.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

        $transfers = $this->inventoryService->getTransfersPaginated(['statuses' => ['DRAFT', 'APPROVED']], $limit);

        return $this->successPaginatedResponse($transfers, 'Daftar transfer baru dibuat (belum dikirim).');
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
            'location_id' => 'bail|nullable|uuid|exists:locations,id',
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $statusFilter = $request->input('filter.status');
        $filters = $statusFilter
            ? ['status' => $statusFilter]
            : ['statuses' => [InventoryTransfer::STATUS_IN_TRANSIT, InventoryTransfer::STATUS_RECEIVED]];

        if (!empty($validated['location_id'])) {
            $filters['destination_location_id'] = $validated['location_id'];
        }

        $transfers = $this->inventoryService->getTransfersPaginated($filters, (int) $request->query('limit', 10));

        return $this->successPaginatedResponse($transfers, 'Daftar transfer masuk.');
    }

    public function transfersOut(\Illuminate\Http\Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => 'bail|nullable|uuid|exists:locations,id',
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
            'page' => 'nullable|integer|min:1',
        ]);

        $filters = ['status' => 'IN_TRANSIT'];
        if (!empty($validated['location_id'])) {
            $filters['source_location_id'] = $validated['location_id'];
        }

        $transfers = $this->inventoryService->getTransfersPaginated($filters, (int) $request->query('limit', 10));

        return $this->successPaginatedResponse($transfers, 'Daftar transfer keluar (dalam perjalanan).');
    }

    public function createDraft(\Illuminate\Http\Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'created_by' => 'required|string|max:100',
                'source_location_id' => 'nullable|uuid|exists:locations,id',
                'destination_location_id' => 'nullable|uuid|exists:locations,id',
                'notes' => 'nullable|string',
                'transfer_number' => 'nullable|string|max:100|unique:inventory_transfers,transfer_number',
            ]);

            $result = $this->inventoryService->createDraft($validated);
            return $this->successResponse(new InventoryTransferResource($result), 'Draft transfer berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function submitDraft(string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->submitDraft($id);
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer berhasil diajukan untuk approval.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function updateDraft(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'source_location_id' => 'nullable|uuid|exists:locations,id',
                'destination_location_id' => 'nullable|uuid|exists:locations,id',
                'notes' => 'nullable|string',
            ]);

            $result = $this->inventoryService->updateDraft($id, $validated);
            return $this->successResponse(new InventoryTransferResource($result), 'Draft transfer berhasil diperbarui.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function addDraftItem(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'item_id' => 'required|uuid|exists:product_variants,id',
                'qty' => 'required|integer|min:1',
                'source_bin_id' => 'nullable|uuid|exists:location_bins,id',
                'destination_bin_id' => 'nullable|uuid|exists:location_bins,id',
                'batch_no' => 'nullable|string|max:100',
                'serial_no' => 'nullable|string|max:100',
            ]);

            $result = $this->inventoryService->addDraftItem($id, $validated);
            return $this->successResponse(new InventoryTransferResource($result), 'Item berhasil ditambahkan ke draft.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function updateDraftItem(\Illuminate\Http\Request $request, string $transferId, string $itemId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'qty' => 'required|integer|min:1',
                'source_bin_id' => 'nullable|uuid|exists:location_bins,id',
            ]);

            $result = $this->inventoryService->updateDraftItemQty(
                $transferId,
                $itemId,
                $validated['qty'],
                array_key_exists('source_bin_id', $validated) ? $validated['source_bin_id'] : null,
                $request->has('source_bin_id'),
            );
            return $this->successResponse(new InventoryTransferResource($result), 'Item berhasil diperbarui.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function removeDraftItem(string $transferId, string $itemId): JsonResponse
    {
        try {
            $this->inventoryService->removeDraftItem($transferId, $itemId);
            return $this->successResponse(null, 'Item berhasil dihapus dari draft.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function revertToDraft(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $actor = $request->user()?->name ?? $request->user()?->email ?? 'system';
            $result = $this->inventoryService->revertToDraft($id, ['actor' => $actor]);
            return $this->successResponse(new InventoryTransferResource($result), 'Transfer dikembalikan ke Baru Dibuat.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal membatalkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
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
            return $this->errorResponse(
                'Gagal memproses putaway.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransfer(\Modules\Inventory\Http\Requests\BinTransferRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            if (empty($data['created_by']) && $request->user()) {
                $data['created_by'] = $request->user()->name ?? $request->user()->email;
            }

            $transfer = $this->inventoryService->createBinTransferDraft($data);

            return $this->successResponse(new BinTransferResource($transfer), 'Transfer internal berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses transfer.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferPrint(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $printedBy = $request->user()->name ?? $request->user()->email ?? 'system';
            $transfer = $this->inventoryService->printBinTransfer($id, $printedBy);

            return $this->successResponse(new BinTransferResource($transfer), 'Surat jalan dicetak, transfer sedang dijalan.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mencetak.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferPdf(string $id)
    {
        $transfer = $this->inventoryService->getBinTransfer($id);

        if (! $transfer) {
            return $this->errorResponse('Transfer internal tidak ditemukan.', 404);
        }

        try {
            $pdf = Pdf::loadView('inventory::pdf.bin-transfer-out', [
                'transfer' => $transfer,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream("{$transfer->transfer_number}.pdf");
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF transfer internal.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferRevertPrint(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        try {
            $actor = $request->user()->name ?? $request->user()->email ?? 'system';
            $transfer = $this->inventoryService->revertBinTransferPrint($id, $actor);

            return $this->successResponse(new BinTransferResource($transfer), 'Transfer dikembalikan ke Baru Dibuat.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mencetak.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferReceive(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
            'received_by' => 'nullable|string|max:100',
            'items' => 'required|array|min:1',
            'items.*.bin_transfer_item_id' => 'required|uuid|exists:bin_transfer_items,id',
            'items.*.destination_bin_id' => 'required|uuid|exists:location_bins,id',
            'items.*.qty' => 'required|integer|min:1',
        ]);

        try {
            if (empty($validated['received_by'])) {
                $validated['received_by'] = $request->user()->name ?? $request->user()->email ?? 'system';
            }

            $receipt = $this->inventoryService->receiveBinTransfer($id, $validated);

            return $this->successResponse(new BinTransferReceiptResource($receipt), 'Penerimaan transfer internal berhasil.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses transfer.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferDestroy(string $id): JsonResponse
    {
        try {
            $this->inventoryService->deleteBinTransferDraft($id);
            return $this->successResponse(null, 'Transfer internal berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferReceiptsIndex(\Illuminate\Http\Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $filters = array_filter([
            'location_id' => $request->query('filter.location_id') ?? $request->query('location_id'),
            'date_from' => $request->query('filter.date_from') ?? $request->query('date_from'),
            'date_to' => $request->query('filter.date_to') ?? $request->query('date_to'),
        ]);

        $paginated = $this->inventoryService->getBinTransferReceipts($filters, $perPage);

        return $this->successPaginatedResponse($paginated, 'Daftar penerimaan transfer internal berhasil diambil.');
    }

    public function binTransferReceiptShow(string $id): JsonResponse
    {
        $receipt = $this->inventoryService->getBinTransferReceipt($id);
        if (! $receipt) {
            return $this->errorResponse('Penerimaan transfer internal tidak ditemukan.', 404);
        }

        return $this->successResponse(new BinTransferReceiptResource($receipt), 'Detail penerimaan transfer internal berhasil diambil.');
    }

    public function binTransferItemDestroy(\Illuminate\Http\Request $request, string $id, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $transfer = $this->inventoryService->reverseBinTransferItem(
                $id,
                $itemId,
                $validated['qty'] ?? null,
                $userId,
            );

            return $this->successResponse(new BinTransferResource($transfer), 'Baris pindah bin berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferItemsDestroy(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $transfer = $this->inventoryService->reverseBinTransferItems($id, $validated['items'], $userId);

            return $this->successResponse(new BinTransferResource($transfer), 'Baris pindah bin berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function binTransferIndex(\Illuminate\Http\Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $filters = [
            'location_id' => $request->query('filter.location_id') ?? $request->query('location_id'),
            'status' => $request->query('filter.status') ?? $request->query('status'),
            'date_from' => $request->query('filter.date_from') ?? $request->query('date_from'),
            'date_to' => $request->query('filter.date_to') ?? $request->query('date_to'),
        ];

        $paginated = $this->inventoryService->getBinTransfers(array_filter($filters), $perPage);

        return $this->successPaginatedResponse($paginated, 'Daftar pindah bin berhasil diambil.');
    }

    public function binTransferShow(string $id): JsonResponse
    {
        $transfer = $this->inventoryService->getBinTransfer($id);
        if (! $transfer) {
            return $this->errorResponse('Pindah bin tidak ditemukan.', 404);
        }

        return $this->successResponse(new BinTransferResource($transfer), 'Detail pindah bin berhasil diambil.');
    }

    public function binTransferUpdate(\Illuminate\Http\Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'transfer_date' => 'nullable|date',
            'created_by' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        try {
            $transfer = $this->inventoryService->updateBinTransferMetadata($id, $data);

            return $this->successResponse(new BinTransferResource($transfer), 'Pindah bin berhasil diperbarui.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

            return $this->successResponse(new BinTransferResource($transfer), 'Transfer ditandai sudah dicetak.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal mencetak.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
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

        return $this->successResponse(new InventoryTransferResource($transfer), 'Data surat jalan transfer berhasil diambil.');
    }
}
