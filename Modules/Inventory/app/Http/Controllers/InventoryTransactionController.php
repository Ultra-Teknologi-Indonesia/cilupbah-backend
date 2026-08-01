<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfRenderer;
use App\Support\ActorName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Http\Resources\InventoryTransferResource;
use Modules\Inventory\Http\Resources\BinTransferResource;
use Modules\Inventory\Http\Resources\BinTransferReceiptResource;
use Modules\Inventory\Http\Requests\AddTransferDraftItemRequest;
use Modules\Inventory\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Http\Requests\ApproveTransferRequest;
use Modules\Inventory\Http\Requests\BulkDeleteTransferRequest;
use Modules\Inventory\Http\Requests\BulkPdfTransferRequest;
use Modules\Inventory\Http\Requests\CancelTransferRequest;
use Modules\Inventory\Http\Requests\CreateTransferDraftRequest;
use Modules\Inventory\Http\Requests\MarkTransferPrintedRequest;
use Modules\Inventory\Http\Requests\PutawayStockRequest;
use Modules\Inventory\Http\Requests\ReceiveBinTransferRequest;
use Modules\Inventory\Http\Requests\ReverseBinTransferItemRequest;
use Modules\Inventory\Http\Requests\ReverseBinTransferItemsRequest;
use Modules\Inventory\Http\Requests\ShipTransferRequest;
use Modules\Inventory\Http\Requests\TransfersInRequest;
use Modules\Inventory\Http\Requests\TransfersOutRequest;
use Modules\Inventory\Http\Requests\TransferStockRequest;
use Modules\Inventory\Http\Requests\UpdateBinTransferRequest;
use Modules\Inventory\Http\Requests\UpdateTransferDraftItemRequest;
use Modules\Inventory\Http\Requests\UpdateTransferDraftRequest;
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
        protected InventoryService $inventoryService,
        protected PdfRenderer $pdfRenderer,
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

    public function approveTransfer(ApproveTransferRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->approveTransfer($id, $request->validated());
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

    public function cancelTransfer(CancelTransferRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->cancelTransfer($id, $request->validated());
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

    public function shipTransfer(ShipTransferRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->shipTransfer($id, $request->validated());
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
            return $this->pdfRenderer->stream(
                'inventory::pdf.transfer-out',
                ['transfer' => $transfer],
                "{$transfer->transfer_number}.pdf",
            );
        } catch (\Throwable $e) {
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
    public function bulkDeleteTransfer(BulkDeleteTransferRequest $request): JsonResponse
    {
        $result = $this->inventoryService->bulkDeleteOrRevertTransfers(
            $request->validated()['ids'],
            ActorName::fromUser($request->user()),
        );

        return $this->successResponse($result, $this->inventoryService->summarizeBulkTransferResult($result));
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
    public function bulkPdfTransfer(BulkPdfTransferRequest $request)
    {
        $transfers = $this->inventoryService->getTransfersForBulkPdf($request->validated()['ids']);

        try {
            return $this->pdfRenderer->stream(
                'inventory::pdf.transfer-out-bulk',
                ['transfers' => $transfers],
                'Surat-Jalan-Bulk-' . now()->format('Ymd-His') . '.pdf',
            );
        } catch (\Throwable $e) {
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

    public function transfersIn(TransfersInRequest $request): JsonResponse
    {
        $transfers = $this->inventoryService->getIncomingTransfersPaginated(
            $request->input('filter.status'),
            $request->query('location_id'),
            (int) $request->query('limit', 10),
        );

        return $this->successPaginatedResponse($transfers, 'Daftar transfer masuk.');
    }

    public function transfersOut(TransfersOutRequest $request): JsonResponse
    {
        $transfers = $this->inventoryService->getOutgoingTransfersPaginated(
            $request->query('location_id'),
            (int) $request->query('limit', 10),
        );

        return $this->successPaginatedResponse($transfers, 'Daftar transfer keluar (dalam perjalanan).');
    }

    public function createDraft(CreateTransferDraftRequest $request): JsonResponse
    {
        try {
            $result = $this->inventoryService->createDraft($request->validated());
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

    public function updateDraft(UpdateTransferDraftRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->updateDraft($id, $request->validated());
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

    public function addDraftItem(AddTransferDraftItemRequest $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->addDraftItem($id, $request->validated());
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

    public function updateDraftItem(UpdateTransferDraftItemRequest $request, string $transferId, string $itemId): JsonResponse
    {
        try {
            $validated = $request->validated();

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

    public function revertToDraft(Request $request, string $id): JsonResponse
    {
        try {
            $result = $this->inventoryService->revertToDraft($id, ['actor' => ActorName::fromUser($request->user())]);
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
                $data['created_by'] = ActorName::fromUser($request->user());
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
            $transfer = $this->inventoryService->printBinTransfer($id, ActorName::fromUser($request->user()));

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
            return $this->pdfRenderer->stream(
                'inventory::pdf.bin-transfer-out',
                ['transfer' => $transfer],
                "{$transfer->transfer_number}.pdf",
            );
        } catch (\Throwable $e) {
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
            $transfer = $this->inventoryService->revertBinTransferPrint($id, ActorName::fromUser($request->user()));

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

    public function binTransferReceive(ReceiveBinTransferRequest $request, string $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            if (empty($validated['received_by'])) {
                $validated['received_by'] = ActorName::fromUser($request->user());
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

    public function binTransferItemDestroy(ReverseBinTransferItemRequest $request, string $id, string $itemId): JsonResponse
    {
        $validated = $request->validated();

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

    public function binTransferItemsDestroy(ReverseBinTransferItemsRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

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

    public function binTransferUpdate(UpdateBinTransferRequest $request, string $id): JsonResponse
    {
        try {
            $transfer = $this->inventoryService->updateBinTransferMetadata($id, $request->validated());

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
    public function markTransferPrinted(MarkTransferPrintedRequest $request): JsonResponse
    {
        try {
            $transfer = $this->inventoryService->markTransferPrinted(
                $request->validated()['transfer_id'],
                ActorName::fromUser($request->user()),
            );

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
