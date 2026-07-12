<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Services\PutawayService;
use Modules\Inventory\Models\Putaway;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Http\Requests\AssignPutawayStaffRequest;
use Modules\Inventory\Http\Requests\ProcessPutawayItemRequest;
use Modules\Warehouse\Models\LocationBin;
use Barryvdh\DomPDF\Facade\Pdf;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Putaway', description: 'API Endpoints for Standalone Putaway')]
class PutawayController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected PutawayService $putawayService
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
    public function store(Request $request): JsonResponse
    {

        if ($request->filled('inbound_id') && ! $request->filled('inbound_ids')) {
            $request->merge(['inbound_ids' => [$request->input('inbound_id')]]);
        }

        $request->validate([
            'inbound_ids' => 'required|array|min:1',
            'inbound_ids.*' => 'required|string|distinct|exists:inbounds,id',
            'assigned_to' => 'nullable|string|exists:users,id',
        ]);

        try {
            $inbounds = \Modules\Inbound\Models\Inbound::with(['items', 'putaways:id,status'])
                ->whereIn('id', $request->inbound_ids)
                ->get();

            if ($inbounds->pluck('location_id')->unique()->count() > 1) {
                return $this->errorResponse('Penerimaan harus dari lokasi/gudang yang sama untuk digabung.', 422);
            }

            foreach ($inbounds as $inbound) {
                if (in_array($inbound->status, [\Modules\Inbound\Models\Inbound::STATUS_COMPLETED, \Modules\Inbound\Models\Inbound::STATUS_CANCELLED], true)) {
                    return $this->errorResponse("Penerimaan {$inbound->transaction_number} sudah {$inbound->status}, tidak bisa dibuat penempatan.", 422);
                }

                $hasActive = $inbound->putaways->contains(
                    fn ($p) => ! in_array($p->status, [Putaway::STATUS_COMPLETED, Putaway::STATUS_CANCELLED], true)
                );
                if ($hasActive) {
                    return $this->errorResponse("Penerimaan {$inbound->transaction_number} sudah memiliki penempatan aktif.", 422);
                }
            }

            $locationId = $inbounds->first()->location_id;
            $defaultBin = app(\Modules\Warehouse\Services\LocationBinService::class)->getDefaultBin($locationId);
            $userId = $request->user()->id ?? 'system';

            $merged = [];
            foreach ($inbounds as $inbound) {
                foreach ($inbound->items as $item) {
                    $pending = max(0, (int) $item->received_qty - (int) $item->putaway_qty);
                    if ($pending <= 0) {
                        continue;
                    }

                    if (! isset($merged[$item->item_id])) {
                        $merged[$item->item_id] = [
                            'item_id'            => $item->item_id,
                            'source_bin_id'      => $defaultBin ? $defaultBin->id : null,
                            'destination_bin_id' => null,
                            'qty'                => 0,
                            'batch_no'           => null,
                            'serial_no'          => null,
                            'sources'            => [],
                        ];
                    }

                    $merged[$item->item_id]['qty'] += $pending;
                    $merged[$item->item_id]['sources'][] = [
                        'inbound_item_id' => $item->id,
                        'qty'             => $pending,
                    ];
                }
            }

            $items = array_values($merged);

            if (empty($items)) {
                return $this->errorResponse('Tidak ada item untuk di-putaway.', 400);
            }

            $notes = $inbounds->count() === 1
                ? "Manual Putaway from Inbound {$inbounds->first()->transaction_number}"
                : 'Manual Putaway gabungan dari ' . $inbounds->count() . ' penerimaan: ' . $inbounds->pluck('transaction_number')->implode(', ');

            $putaway = $this->putawayService->create([
                'location_id' => $locationId,
                'source_type' => 'INBOUND',

                'source_id'   => $inbounds->count() === 1 ? $inbounds->first()->id : null,
                'sources'     => $inbounds->pluck('id')->all(),
                'notes'       => $notes,
                'created_by'  => $userId,
                'items'       => $items,
            ]);

            if ($request->assigned_to) {
                app(\Modules\Inventory\Services\PutawayService::class)->assignStaff([
                    'performed_by' => $userId,
                    'data' => [
                        [
                            'putaway_id' => $putaway->id,
                            'assigned_to' => $request->assigned_to,
                        ]
                    ]
                ]);
            }

            return $this->successResponse($putaway, 'Penempatan barang berhasil dibuat.', 201);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getAllPaginated($limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway berhasil diambil.');
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
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_NOT_STARTED, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway NOT_STARTED berhasil diambil.');
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
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_IN_PROGRESS, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway IN_PROGRESS berhasil diambil.');
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
        $limit = $request->query('limit', 10);
        $putaways = $this->putawayService->getByStatus(Putaway::STATUS_COMPLETED, $limit);

        return $this->successPaginatedResponse($putaways, 'Daftar putaway COMPLETED berhasil diambil.');
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
            $limit = $request->query('limit', 10);
            $items = $this->putawayService->getItems($id, $limit);

            return $this->successPaginatedResponse($items, 'Daftar item putaway berhasil diambil.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                404,
                ['detail' => $e->getMessage()],
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
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menetapkan.',
                422,
                ['detail' => $e->getMessage()],
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
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memulai.',
                422,
                ['detail' => $e->getMessage()],
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

            return $this->successResponse(null, 'Proses putaway item sedang dijalankan.', 202);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
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
    public function deletePlacement(Request $request, string $id, string $itemId, string $placementId): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

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
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
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
    public function deletePlacements(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.placement_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $putaway = $this->putawayService->deletePlacements($id, $validated['items'], $userId);

            return $this->successResponse($putaway, 'Penempatan berhasil dikoreksi.');
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
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyelesaikan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/putaway/{id}/complete-discrepancy',
        summary: 'Selesaikan putaway meski ada selisih fisik; sisa qty ditempatkan ke rak default',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil diselesaikan dengan selisih dialokasikan ke rak default.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function completeDiscrepancy(Request $request, string $id): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $result = $this->putawayService->completeWithDiscrepancy($id, $userId);

            return $this->successResponse($result, 'Putaway berhasil diselesaikan. Selisih dialokasikan ke rak default.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menyelesaikan.',
                422,
                ['detail' => $e->getMessage()],
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
    public function listBins(Request $request): JsonResponse
    {
        $request->validate([
            'location_id' => 'required|string',
        ]);

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
    public function lookupBin(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'location_id' => 'required|string',
        ]);

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

            $prepared = $this->preparePutawayForPdf($putaway);

            $printedBy = $request->user()->name ?? $request->user()->email ?? '-';

            $pdf = Pdf::loadView('inventory::pdf.putaway', [
                'putaway' => $prepared['putaway'],
                'qrDataUri' => $prepared['qrDataUri'],
                'printedBy' => $printedBy,
                'sourceLabel' => $prepared['sourceLabel'],
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF putaway.',
                500,
                ['detail' => $e->getMessage()],
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
    public function bulkPdf(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:50',
            'ids.*' => 'required|string',
        ]);

        try {
            $ids = array_values(array_unique($validated['ids']));

            $putaways = $this->putawayService->getManyForPdf($ids);

            if ($putaways->count() !== count($ids)) {
                $missing = array_values(array_diff($ids, $putaways->pluck('id')->all()));
                return $this->errorResponse('Sebagian penempatan tidak ditemukan: ' . implode(', ', $missing), 404);
            }

            $docs = $putaways->map(fn ($p) => $this->preparePutawayForPdf($p))->all();

            $printedBy = $request->user()->name ?? $request->user()->email ?? '-';
            $filename = 'Putaway-Bulk-' . now()->format('Ymd-His') . '.pdf';

            $pdf = Pdf::loadView('inventory::pdf.putaway-bulk', [
                'docs' => $docs,
                'printedBy' => $printedBy,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF putaway bulk.',
                500,
                ['detail' => $e->getMessage()],
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

            $message = match ($result['action']) {
                'unassigned' => 'Penempatan dihapus, penerimaan dikembalikan.',
                'reset_not_started' => 'Penempatan direset ke Belum Mulai.',
                'reset_in_progress' => 'Penempatan dikembalikan ke Sedang Diproses.',
                default => 'Penempatan diperbarui.',
            };

            return $this->successResponse($result, $message);
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
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
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:100',
            'ids.*' => 'required|string|distinct|exists:putaways,id',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $results = $this->putawayService->bulkDeletePutaway($validated['ids'], $userId);

            return $this->successResponse($results, 'Penempatan terpilih berhasil diproses.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal menghapus.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    protected function preparePutawayForPdf($putaway): array
    {
        $putaway->load(['inbound', 'sources:id,reference_number,transaction_number']);

        $this->attachRecommendedBins($putaway);

        return [
            'putaway' => $putaway,
            'qrDataUri' => $this->generateQrDataUri((string) ($putaway->putaway_no ?? 'PUT')),
            'sourceLabel' => $this->resolveSourceLabel($putaway),
        ];
    }

    protected function attachRecommendedBins($putaway): void
    {
        $items = $putaway->items ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        $locationId = $putaway->location_id;
        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        $stocks = Inventory::query()
            ->whereIn('item_id', $itemIds)
            ->where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->whereHas('bin', fn ($q) => $q->where('is_inbound', false))
            ->with('bin:id,bin_final_code')
            ->orderByDesc('on_hand')
            ->get(['id', 'item_id', 'bin_id', 'on_hand']);

        $byItem = $stocks->groupBy('item_id');

        $allBins = LocationBin::where('location_id', $locationId)
            ->where('is_inbound', false)
            ->orderBy('bin_final_code')
            ->get(['id', 'bin_final_code']);

        $binItemIds = Inventory::where('location_id', $locationId)
            ->where('on_hand', '>', 0)
            ->whereNotNull('bin_id')
            ->select('bin_id', 'item_id')
            ->distinct()
            ->get()
            ->groupBy('bin_id')
            ->map(fn ($rows) => $rows->pluck('item_id')->unique()->values()->all());

        $usedBinItems = [];

        foreach ($items as $item) {
            $remaining = (int) $item->qty;
            $plan = [];
            $usedBinIds = [];

            $itemStocks = $byItem->get($item->item_id, collect());
            foreach ($itemStocks as $stock) {
                if ($remaining <= 0) break;
                $bin = $stock->bin;
                if (!$bin) continue;

                $existingRecommended = $usedBinItems[$bin->id] ?? [];
                if (!empty($existingRecommended) && !in_array($item->item_id, $existingRecommended)) {
                    continue;
                }

                $allocate = $remaining;
                $plan[] = ['code' => $bin->bin_final_code, 'qty' => $allocate];
                $usedBinItems[$bin->id][] = $item->item_id;
                $usedBinIds[] = $bin->id;
                $remaining -= $allocate;
            }

            if ($remaining > 0) {
                foreach ($allBins as $bin) {
                    if ($remaining <= 0) break;
                    if (in_array($bin->id, $usedBinIds)) continue;

                    $existingItemIds = array_unique(array_merge(
                        $binItemIds[$bin->id] ?? [],
                        $usedBinItems[$bin->id] ?? []
                    ));
                    if (!empty($existingItemIds) && !in_array($item->item_id, $existingItemIds)) {
                        continue;
                    }

                    $allocate = $remaining;
                    $plan[] = ['code' => $bin->bin_final_code, 'qty' => $allocate];
                    $usedBinItems[$bin->id][] = $item->item_id;
                    $usedBinIds[] = $bin->id;
                    $remaining -= $allocate;
                }
            }

            $item->recommended_bins = $plan;
        }
    }

    protected function resolveSourceLabel($putaway): string
    {
        if ($putaway->source_type === 'INBOUND') {

            if ($putaway->inbound) {
                return $putaway->inbound->reference_number
                    ?? $putaway->inbound->transaction_number
                    ?? '-';
            }

            $sources = $putaway->relationLoaded('sources') ? $putaway->sources : collect();
            if ($sources->isNotEmpty()) {
                return $sources
                    ->map(fn ($i) => $i->reference_number ?? $i->transaction_number)
                    ->filter()
                    ->implode(', ') ?: '-';
            }
        }

        return '-';
    }

    protected function generateQrDataUri(string $content): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size(160)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,' . base64_encode((string) $svg);
        } catch (Throwable $e) {
            report($e);
            return null;
        }
    }
}
