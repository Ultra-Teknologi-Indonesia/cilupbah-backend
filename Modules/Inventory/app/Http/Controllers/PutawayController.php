<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
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
        summary: 'Create a new putaway manually from an inbound document',
        security: [['bearerAuth' => []]],
        tags: ['Putaway'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['inbound_id'],
                properties: [
                    new OA\Property(property: 'inbound_id', type: 'string'),
                    new OA\Property(property: 'assigned_to', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Putaway created successfully'),
            new OA\Response(response: 400, description: 'Bad request')
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'inbound_id' => 'required|string|exists:inbounds,id',
            'assigned_to' => 'nullable|string|exists:users,id',
        ]);

        try {
            $inbound = \Modules\Inbound\Models\Inbound::with('items')->findOrFail($request->inbound_id);
            $defaultBin = app(\Modules\Warehouse\Services\LocationBinService::class)->getDefaultBin($inbound->location_id);
            $userId = $request->user()->id ?? 'system';

            $items = $inbound->items
                ->filter(fn ($item) => $item->received_qty > 0)
                ->map(fn ($item) => [
                    'item_id'            => $item->item_id,
                    'source_bin_id'      => $defaultBin ? $defaultBin->id : null,
                    'destination_bin_id' => null,
                    'qty'                => $item->received_qty,
                    'batch_no'           => null,
                    'serial_no'          => null,
                ])
                ->values()
                ->toArray();

            if (empty($items)) {
                return $this->errorResponse('Tidak ada item untuk di-putaway.', 400);
            }

            $putaway = $this->putawayService->create([
                'location_id' => $inbound->location_id,
                'source_type' => 'INBOUND',
                'source_id'   => $inbound->id,
                'notes'       => "Manual Putaway from Inbound {$inbound->transaction_number}",
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
            return $this->errorResponse($e->getMessage(), 500);
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
            return $this->errorResponse($e->getMessage(), 404);
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
            return $this->errorResponse($e->getMessage(), 422);
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
            return $this->errorResponse($e->getMessage(), 422);
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
            return $this->errorResponse($e->getMessage(), 422);
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
            return $this->errorResponse($e->getMessage(), 422);
        }
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

        $bin = LocationBin::where('location_id', $locationId)
            ->where('is_inbound', false)
            ->where(fn ($q) => $q->where('bin_final_code', $code)->orWhere('id', $code))
            ->first();

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
                return response()->json(['success' => false, 'message' => 'Putaway tidak ditemukan.'], 404);
            }

            $putawayNo = $putaway->putaway_no ?? 'PUT';
            $filename = "PUTAWAY-{$putawayNo}.pdf";

            $this->attachRecommendedBins($putaway);

            $qrDataUri = $this->generateQrDataUri((string) $putawayNo);

            $printedBy = $request->user()->name ?? $request->user()->email ?? '-';

            $pdf = Pdf::loadView('inventory::pdf.putaway', [
                'putaway' => $putaway,
                'qrDataUri' => $qrDataUri,
                'printedBy' => $printedBy,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat PDF putaway: ' . $e->getMessage(),
            ], 500);
        }
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

        foreach ($items as $item) {
            $top = $byItem->get($item->item_id)?->first();
            $item->recommended_bin_code = optional($top?->bin)->bin_final_code;
        }
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
