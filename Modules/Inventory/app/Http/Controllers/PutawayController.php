<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\PdfRenderer;
use App\Traits\ApiResponse;
use App\Traits\AutoScopeMobileToAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\PutawayPdfPresenter;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Http\Requests\AssignPutawayStaffRequest;
use Modules\Inventory\Http\Requests\BulkDestroyPutawayRequest;
use Modules\Inventory\Http\Requests\BulkPdfPutawayRequest;
use Modules\Inventory\Http\Requests\DeletePlacementRequest;
use Modules\Inventory\Http\Requests\DeletePlacementsRequest;
use Modules\Inventory\Http\Requests\ListPutawayBinsRequest;
use Modules\Inventory\Http\Requests\LookupPutawayBinRequest;
use Modules\Inventory\Http\Requests\ProcessPutawayItemRequest;
use Modules\Inventory\Http\Requests\ResetPutawayAssignmentRequest;
use Modules\Inventory\Http\Requests\StorePutawayRequest;
use Modules\Inventory\Http\Requests\UnassignPutawayRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Putaway', description: 'API Endpoints for Standalone Putaway')]
class PutawayController extends Controller
{
    use ApiResponse;
    use AutoScopeMobileToAuth;

    public function __construct(
        protected PutawayService $putawayService,
        protected PutawayPdfPresenter $pdfPresenter,
        protected PdfRenderer $pdfRenderer,
    ) {}

    #[OA\Get(
        path: '/api/v1/putaway',
        summary: 'Get list of all putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[assigned_to]', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway berhasil diambil.'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ]
    )]
        #[OA\Post(
        path: '/api/v1/putaway',
        summary: 'Create a putaway manually from one or more inbound documents (merged into one progress)',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['inbound_ids'],
                properties: [
                    new OA\Property(property: 'inbound_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'inbound_id', type: 'string', nullable: true, description: 'Deprecated, single-inbound backward-compat'),
                    new OA\Property(property: 'assigned_to', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Putaway created successfully'),
            new OA\Response(response: 400, description: 'Bad request'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function store(StorePutawayRequest $request): JsonResponse
    {
        $userId = $request->user()->id ?? 'system';

        try {
            $result = $this->putawayService->createFromInbounds(
                $request->input('inbound_ids'),
                $request->input('assigned_to'),
                $userId,
            );

            return $this->successResponse($result, 'Penempatan barang berhasil dibuat.', 201);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Throwable $e) {
            report($e);

            return $this->errorResponse(
                $e->getMessage() ?: 'Gagal membuat penempatan barang.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function index(Request $request): JsonResponse
    {

        $this->forceMobileScopeToAuth($request, 'assigned_to');

        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $putaways = $this->putawayService->getAllPaginated($limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/counts',
        summary: 'Get count of putaway documents grouped by status',
        description: 'Dedicated endpoint untuk badge angka di filter tabs mobile — 1 request untuk semua tab, tanpa perlu 3x paginated call.',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[assigned_to]', in: 'query', required: false, description: 'Scope hitungan ke putaway yang di-assign ke user tertentu (biasanya diri sendiri di mobile).', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Jumlah putaway per status',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'data', type: 'object', example: [
                        'NOT_STARTED' => 3,
                        'IN_PROGRESS' => 1,
                        'COMPLETED' => 5,
                        'CANCELLED' => 0,
                    ]),
                ])
            ),
        ]
    )]
    public function counts(Request $request): JsonResponse
    {

        $this->forceMobileScopeToAuth($request, 'assigned_to');

        $filter = (array) $request->query('filter', []);
        $counts = $this->putawayService->getStatusCounts(
            locationId: $filter['location_id'] ?? null,
            assignedTo: $filter['assigned_to'] ?? null,
        );

        return $this->successResponse($counts, 'Jumlah putaway per status');
    }

    #[OA\Get(
        path: '/api/v1/putaway/not-started',
        summary: 'Get list of not started putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway NOT_STARTED berhasil diambil.'),
        ]
    )]
    public function notStarted(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Putaway::STATUS_NOT_STARTED, 'Daftar putaway NOT_STARTED berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/in-progress',
        summary: 'Get list of in-progress putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway IN_PROGRESS berhasil diambil.'),
        ]
    )]
    public function inProgress(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Putaway::STATUS_IN_PROGRESS, 'Daftar putaway IN_PROGRESS berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/completed',
        summary: 'Get list of completed putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar putaway COMPLETED berhasil diambil.'),
        ]
    )]
    public function completed(Request $request): JsonResponse
    {
        return $this->listByStatus($request, Putaway::STATUS_COMPLETED, 'Daftar putaway COMPLETED berhasil diambil.');
    }

    private function listByStatus(Request $request, string $status, string $message): JsonResponse
    {
        $this->forceMobileScopeToAuth($request, 'assigned_to');
        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $putaways = $this->putawayService->getByStatus($status, $limit);

        return $this->successPaginatedResponse($putaways, $message);
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}',
        summary: 'Get putaway document detail',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail putaway berhasil diambil.'),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan.'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->getById($id);
        } catch (\Illuminate\Database\QueryException $e) {
            return $this->errorResponse('Putaway tidak ditemukan.', 404);
        }

        if (!$putaway) {
            return $this->errorResponse('Putaway tidak ditemukan.', 404);
        }

        return $this->successResponse($putaway, 'Detail putaway berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}/history',
        summary: 'Get putaway activity history & audit log',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Riwayat aktivitas penempatan berhasil diambil.'),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan.'),
        ]
    )]
    public function history(string $id): JsonResponse
    {
        try {
            $history = $this->putawayService->getHistory($id);

            return $this->successResponse($history, 'Riwayat aktivitas penempatan berhasil diambil.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return $this->errorResponse('Gagal mengambil riwayat penempatan.', 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}/items',
        summary: 'Get putaway items',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar item putaway berhasil diambil.'),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan.'),
        ]
    )]
    public function items(Request $request, string $id): JsonResponse
    {
        try {
            $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
            $items = $this->putawayService->getItems($id, $limit);

            return $this->successPaginatedResponse($items, 'Daftar item putaway berhasil diambil.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal memproses aksi.',
                404,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/assign-staff',
        summary: 'Assign staff to putaway documents',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['data', 'performed_by'],
            properties: [
                new OA\Property(property: 'data', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'putaway_id', type: 'string'),
                        new OA\Property(property: 'assigned_to', type: 'integer'),
                    ]
                )),
                new OA\Property(property: 'performed_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Staff berhasil di-assign ke putaway.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function assignStaff(AssignPutawayStaffRequest $request): JsonResponse
    {
        try {
            $results = $this->putawayService->assignStaff($request->validated());

            return $this->successResponse($results, 'Staff berhasil di-assign ke putaway.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menetapkan.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/start',
        summary: 'Start a putaway process',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil dimulai.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->start($id);

            return $this->successResponse($putaway, 'Putaway berhasil dimulai.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal memulai.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/items/{itemId}/process',
        summary: 'Process a putaway item',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['destination_bin_id', 'qty'],
            properties: [
                new OA\Property(property: 'destination_bin_id', type: 'string'),
                new OA\Property(property: 'qty', type: 'integer'),
            ]
        )),
        responses: [
            new OA\Response(response: 202, description: 'Proses putaway item sedang dijalankan.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function processItem(ProcessPutawayItemRequest $request, string $id, string $itemId): JsonResponse
    {
        try {
            $this->putawayService->processItem($id, $itemId, $request->validated());

            return $this->successResponse(null, 'Item berhasil ditempatkan.', 200);
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                null,
                'Penempatan ditolak',
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                null,
                'Penempatan tidak dapat diproses',
            );
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                $e->getMessage() ?: 'Gagal memproses aksi.',
                422,
                null,
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Patch(
        path: '/api/v1/putaway/{id}/items/{itemId}/notes',
        summary: 'Update catatan/keterangan pada item putaway',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'notes', type: 'string', nullable: true, description: 'Catatan atau keterangan item'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Catatan item berhasil diperbarui.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function updateItemNotes(Request $request, string $id, string $itemId): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $item = $this->putawayService->updateItemNotes($id, $itemId, $validated['notes'] ?? null);

            return $this->successResponse($item, 'Catatan item berhasil diperbarui.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                $e->getMessage() ?: 'Gagal memperbarui catatan item.',
                422,
            );
        }
    }

    #[OA\Delete(
        path: '/api/v1/putaway/{id}/items/{itemId}/placements/{placementId}',
        summary: 'Hapus/koreksi satu penempatan (salah scan rak/qty) dan kembalikan stok ke rak asal',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'placementId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'qty', type: 'integer', nullable: true, description: 'Qty yang dikoreksi; kosong = seluruh baris'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Penempatan berhasil dikoreksi.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function deletePlacement(DeletePlacementRequest $request, string $id, string $itemId, string $placementId): JsonResponse
    {
        $validated = $request->validated();

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $putaway = $this->putawayService->deletePlacement(
                $id,
                $itemId,
                $placementId,
                $validated['qty'] ?? null,
                $userId,
            );

            return $this->successResponse($putaway, 'Penempatan berhasil dikoreksi.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Delete(
        path: '/api/v1/putaway/{id}/placements',
        summary: 'Koreksi massal beberapa penempatan sekaligus dalam 1 request',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['items'],
            properties: [
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'placement_id'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'placement_id', type: 'string'),
                        new OA\Property(property: 'qty', type: 'integer', nullable: true),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Penempatan berhasil dikoreksi.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function deletePlacements(DeletePlacementsRequest $request, string $id): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $putaway = $this->putawayService->deletePlacements($id, $request->validated()['items'], $userId);

            return $this->successResponse($putaway, 'Penempatan berhasil dikoreksi.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/complete',
        summary: 'Complete a putaway process',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil diselesaikan.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        try {
            $putaway = $this->putawayService->complete($id);

            return $this->successResponse($putaway, 'Putaway berhasil diselesaikan.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menyelesaikan.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/putaway/bins',
        summary: 'List available bins for putaway',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar rak berhasil diambil.'),
        ]
    )]
    public function listBins(ListPutawayBinsRequest $request): JsonResponse
    {
        $locationId = $request->query('location_id');
        $search = $request->query('search', '');

        $result = $this->putawayService->listBins($locationId, $search ?: null);

        return $this->successResponse($result, 'Daftar rak berhasil diambil.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/bins/lookup',
        summary: 'Lookup bin by code for putaway scanning',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'code', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'location_id', in: 'query', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Bin ditemukan.'),
            new OA\Response(response: 404, description: 'Bin tidak ditemukan.'),
        ]
    )]
    public function lookupBin(LookupPutawayBinRequest $request): JsonResponse
    {
        $code = $request->query('code');
        $locationId = $request->query('location_id');

        $bin = $this->putawayService->lookupBin($code, $locationId);

        if (!$bin) {
            return $this->errorResponse('Rak tidak ditemukan.', 404);
        }

        return $this->successResponse($bin, 'Bin ditemukan.');
    }

    #[OA\Get(
        path: '/api/v1/putaway/{id}/pdf',
        summary: 'Cetak dokumen Putaway sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Putaway tidak ditemukan'),
        ]
    )]
    public function pdf(Request $request, string $id)
    {
        try {
            $putaway = $this->putawayService->getById($id);

            if (!$putaway) {
                return $this->errorResponse('Putaway tidak ditemukan.', 404);
            }

            $putawayNo = $putaway->putaway_no ?? 'PUT';
            $filename = "PUTAWAY-{$putawayNo}.pdf";

            $prepared = $this->pdfPresenter->present($putaway);

            return $this->pdfRenderer->stream('inventory::pdf.putaway', [
                'putaway' => $prepared['putaway'],
                'qrDataUri' => $prepared['qrDataUri'],
                'printedBy' => \App\Support\ActorName::fromUser($request->user(), '-'),
                'sourceLabel' => $prepared['sourceLabel'],
            ], $filename);
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF putaway.',
                500,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/bulk/pdf',
        summary: 'Cetak banyak dokumen Putaway (semua status) menjadi satu file PDF',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Sebagian putaway tidak ditemukan'),
        ]
    )]
    public function bulkPdf(BulkPdfPutawayRequest $request)
    {
        $ids = array_values(array_unique($request->validated()['ids']));

        $putaways = $this->putawayService->getManyForPdfOrFail($ids);

        try {
            return $this->pdfRenderer->stream('inventory::pdf.putaway-bulk', [
                'docs' => $this->pdfPresenter->presentMany($putaways),
                'printedBy' => \App\Support\ActorName::fromUser($request->user(), '-'),
            ], 'Putaway-Bulk-' . now()->format('Ymd-His') . '.pdf');
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF putaway bulk.',
                500,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Delete(
        path: '/api/v1/putaway/{id}',
        summary: 'Hapus dokumen putaway (revert bertingkat sesuai status)',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Penempatan berhasil diproses.'),
            new OA\Response(response: 422, description: 'Status tidak valid / gagal.'),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $result = $this->putawayService->deletePutaway($id, $userId);

            return $this->successResponse($result, $this->putawayService->messageForDeleteAction($result['action'] ?? null));
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Delete(
        path: '/api/v1/putaway/bulk',
        summary: 'Hapus banyak dokumen putaway sekaligus (per-baris sesuai statusnya, atomik)',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['ids'],
            properties: [
                new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Penempatan terpilih berhasil diproses.'),
            new OA\Response(response: 422, description: 'Validation Error / gagal.'),
        ]
    )]
    public function bulkDestroy(BulkDestroyPutawayRequest $request): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $results = $this->putawayService->bulkDeletePutaway($request->validated()['ids'], $userId);

            return $this->successResponse($results, 'Penempatan terpilih berhasil diproses.');
        } catch (\App\Exceptions\UserFacingException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                app()->environment('production') ? null : ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function unassign(string $id, UnassignPutawayRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $putaway = $this->putawayService->unassign(
            $id,
            (string) $request->user()->id,
            \App\Enums\UnassignReasonEnum::from($validated['reason_code']),
            $validated['reason_note'] ?? null,
            $validated['new_assignee_id'] ?? null,
        );

        return $this->successResponse(
            $putaway,
            $validated['new_assignee_id'] ?? false
                ? 'Tugas putaway berhasil dialihkan.'
                : 'Assignment putaway berhasil dibatalkan. Dokumen kembali ke antrian web.',
        );
    }

    public function resetAssignment(string $id, ResetPutawayAssignmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $putaway = $this->putawayService->resetAssignmentDestructive(
            $id,
            (string) $request->user()->id,
            $validated['reason_note'],
            $validated['new_assignee_id'] ?? null,
        );

        return $this->successResponse(
            $putaway,
            'Putaway berhasil di-reset. Semua penempatan telah dibalik.',
        );
    }
}
