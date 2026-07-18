<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\AutoScopeMobileToAuth;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Outbound\Http\Resources\PicklistResource;
use Modules\Outbound\Repositories\PicklistRepository;
use Modules\Outbound\Services\PicklistService;
use Modules\Outbound\Http\Requests\CreatePicklistRequest;
use Modules\Outbound\Http\Requests\PickItemRequest;
use Modules\Outbound\Http\Requests\FailPickItemRequest;

use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Report\Services\ReportService;
use OpenApi\Attributes as OA;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

#[OA\Tag(name: 'Outbound - Picklist', description: 'API Endpoints for Picklist management')]
#[OA\Schema(
    schema: 'Picklist',
    title: 'Picklist Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'picklist_no', type: 'string', example: 'PK-20260608-0001'),
        new OA\Property(property: 'location_id', type: 'string'),
        new OA\Property(property: 'picker_id', type: 'string', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED']),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'notes', type: 'string', nullable: true),
        new OA\Property(property: 'created_by', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class PicklistController extends Controller
{
    use AutoScopeMobileToAuth;

    public function __construct(
        protected PicklistService $picklistService,
        protected ReportService $reportService,
        protected PicklistRepository $picklistRepository,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/picklists',
        summary: 'Get paginated picklists',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[picker_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        // Auto-scope mobile ke picker login (X-Client-Channel: MOBILE).
        $this->forceMobileScopeToAuth($request, 'picker_id');

        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->picklistService->getAllPaginated($limit);

        return $this->successPaginatedResponse(PicklistResource::collection($data));
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/counts',
        summary: 'Get count of picklists grouped by status',
        description: 'Dedicated endpoint untuk badge angka di filter tabs mobile — 1 request untuk semua tab, tanpa perlu 3x paginated call.',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[picker_id]', in: 'query', required: false, description: 'Scope hitungan ke picklist milik picker tertentu (biasanya diri sendiri di mobile).', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Jumlah picklist per status',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'string', example: 'success'),
                    new OA\Property(property: 'data', type: 'object', example: [
                        'DRAFT' => 4,
                        'IN_PROGRESS' => 1,
                        'COMPLETED' => 2,
                        'FAILED' => 0,
                        'CANCELLED' => 0,
                    ]),
                ])
            ),
        ]
    )]
    public function counts(Request $request): JsonResponse
    {
        // Auto-scope mobile ke picker login (X-Client-Channel: MOBILE).
        $this->forceMobileScopeToAuth($request, 'picker_id');

        $filter = (array) $request->query('filter', []);
        $counts = $this->picklistService->getStatusCounts(
            locationId: $filter['location_id'] ?? null,
            pickerId: $filter['picker_id'] ?? null,
        );

        return $this->successResponse($counts, 'Jumlah picklist per status');
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists',
        summary: 'Create a new picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['order_ids', 'location_id'],
                properties: [
                    new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
                    new OA\Property(property: 'location_id', type: 'string'),
                    new OA\Property(property: 'picker_id', type: 'string', nullable: true),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreatePicklistRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = auth()->user()->id;

        $picklist = $this->picklistService->create($data);

        return $this->successResponse($picklist, null, 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Get picklist detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $picklist = $this->picklistService->getById($id);

        if (!$picklist) {
            return $this->errorResponse('Picklist tidak ditemukan.', 404);
        }

        return $this->successResponse(new PicklistResource($picklist));
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}/items',
        summary: 'Get picklist items',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function items(string $id, Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->picklistService->getItems($id, $limit);

        return $this->successPaginatedResponse($data);
    }

    #[OA\Get(
        path: '/api/v1/outbound/picklists/{id}/pdf',
        summary: 'Cetak dokumen Picklist sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Picklist tidak ditemukan'),
        ]
    )]
    public function pdf(string $id)
    {
        validator(['id' => $id], ['id' => 'required|string|exists:picklists,id'])->validate();

        try {
            $report = $this->reportService->pickListReport(['picklist_id' => $id]);
            $picklist = $report['data'] ?? null;

            if (!$picklist) {
                return $this->errorResponse('Picklist tidak ditemukan.', 404);
            }

            $picklistNo = $picklist->picklist_no ?? 'PICK';
            $filename = "PICK-{$picklistNo}.pdf";

            $filename = str_starts_with((string) $picklistNo, 'PK-')
                ? "{$picklistNo}.pdf"
                : "PICK-{$picklistNo}.pdf";

            $this->attachRecommendedBins($picklist);

            $qrDataUri = $this->generateQrDataUri((string) $picklistNo);

            $pdf = Pdf::loadView('outbound::pdf.picklist', [
                'picklist' => $picklist,
                'qrDataUri' => $qrDataUri,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF picklist.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/documents/bulk/pdf',
        summary: 'Cetak Picklist untuk banyak pesanan (order_ids) dalam 1 PDF multi-halaman',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_ids'],
            properties: [
                new OA\Property(property: 'order_ids', type: 'array', items: new OA\Items(type: 'string')),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Tidak ada picklist ditemukan untuk pesanan tersebut'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function bulkPdf(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array|min:1|max:200',
            'order_ids.*' => 'required|string',
        ]);

        try {
            $orderIds = $validated['order_ids'];

            $picklists = $this->picklistRepository->getForBulkPdf($orderIds);

            if ($picklists->isEmpty()) {
                return $this->errorResponse('Tidak ada picklist ditemukan untuk pesanan yang dipilih.', 404);
            }

            foreach ($picklists as $picklist) {
                $this->attachRecommendedBins($picklist);
            }

            $qrMap = $picklists->mapWithKeys(
                fn ($picklist) => [$picklist->id => $this->generateQrDataUri((string) ($picklist->picklist_no ?? ''))]
            );

            $filename = 'Picklist-Bulk-' . now()->format('Ymd-His') . '.pdf';

            $pdf = Pdf::loadView('outbound::pdf.picklist-bulk', [
                'picklists' => $picklists,
                'qrMap' => $qrMap,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF picklist bulk.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/assign-picker',
        summary: 'Assign picker to picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['picker_id'],
                properties: [
                    new OA\Property(property: 'picker_id', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function assignPicker(string $id, Request $request): JsonResponse
    {
        $request->validate(['picker_id' => 'required|string|exists:users,id']);

        $picklist = $this->picklistService->assignPicker($id, $request->picker_id, auth()->user()->id);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/start',
        summary: 'Start picking process',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function start(string $id): JsonResponse
    {
        $picklist = $this->picklistService->start($id);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/pick',
        summary: 'Record pick for an item',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qty_picked', 'bin_code'],
                properties: [
                    new OA\Property(property: 'qty_picked', type: 'integer', minimum: 0),
                    new OA\Property(property: 'bin_code', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function pickItem(string $id, string $itemId, PickItemRequest $request): JsonResponse
    {
        try {
            $this->picklistService->pickItem($id, $itemId, $request->validated());
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses picking.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Item berhasil di-pick.');
    }

    #[OA\Delete(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/pick',
        summary: 'Koreksi salah scan pick: kembalikan stok yang sudah di-pick ke rak asal',
        security: [['bearerAuth' => []]],
        tags: ['Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'qty', type: 'integer', nullable: true, description: 'Qty yang dikoreksi; kosong = seluruh qty yang sudah di-pick'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Pick berhasil dikoreksi.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function unpickItem(string $id, string $itemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $picklist = $this->picklistService->unpickItem($id, $itemId, $validated['qty'] ?? null, $userId);

            return $this->successResponse($picklist, 'Pick berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses picking.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function unpickItems(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $picklist = $this->picklistService->unpickItems($id, $validated['items'], $userId);

            return $this->successResponse($picklist, 'Pick berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses picking.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/fail',
        summary: 'Fail single picklist item (mark SHORT/REJECTED without stock mutation)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason_code'],
                properties: [
                    new OA\Property(property: 'reason_code', type: 'string', enum: ['STOCK_EMPTY', 'DAMAGED', 'REJECTED', 'MISSING', 'OTHER']),
                    new OA\Property(property: 'reason_note', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Item ditandai gagal.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function failItem(string $id, string $itemId, FailPickItemRequest $request): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $validated = $request->validated();
            $picklist = $this->picklistService->failPickItem(
                $id,
                $itemId,
                $validated['reason_code'],
                $validated['reason_note'] ?? null,
                $userId,
            );

            return $this->successResponse($picklist, 'Item ditandai gagal.');
        } catch (OutboundValidationException $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal menandai item.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/items/{itemId}/unfail',
        summary: 'Undo fail flag on a picklist item',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Fail flag di-reset.'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function unfailItem(string $id, string $itemId, Request $request): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $picklist = $this->picklistService->unfailPickItem($id, $itemId, $userId);

            return $this->successResponse($picklist, 'Fail item dibatalkan.');
        } catch (OutboundValidationException $e) {
            return $this->errorResponse(
                'Gagal memproses aksi.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membatalkan fail item.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }


    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/scan',
        summary: 'Resolve SKU→bin: auto-suggest bin from inventory kalau bin_code kosong; strict validate kalau diberi (no stock mutation)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['sku'],
                properties: [
                    new OA\Property(property: 'sku', type: 'string'),
                    new OA\Property(property: 'bin_code', type: 'string', nullable: true, description: 'Override manual. Kalau diisi → jalur strict. Kalau tidak → BE auto-resolve dari inventory.'),
                    new OA\Property(property: 'hint_active_bin_code', type: 'string', nullable: true, description: 'Rak aktif di UI. Dipakai BE untuk prefer bin ini kalau masih valid.'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function scan(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku' => 'required|string',
            'bin_code' => 'nullable|string',
            'hint_active_bin_code' => 'nullable|string',
        ]);

        $result = $this->picklistService->scanForPick(
            $id,
            $validated['sku'],
            $validated['bin_code'] ?? null,
            $validated['hint_active_bin_code'] ?? null,
        );

        return $this->successResponse($result);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/complete',
        summary: 'Complete picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        $picklist = $this->picklistService->complete($id);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/fail',
        summary: 'Mark picklist as failed',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function fail(string $id, Request $request): JsonResponse
    {
        $picklist = $this->picklistService->failPick($id, $request->reason);

        return $this->successResponse($picklist);
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/cancel',
        summary: 'Cancel picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        $picklist = $this->picklistService->cancel($id);

        return $this->successResponse($picklist);
    }

    #[OA\Delete(
        path: '/api/v1/outbound/picklists/{id}',
        summary: 'Delete draft picklist',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->picklistService->delete($id);

        return $this->successResponse(null, 'Picklist berhasil dihapus.');
    }

    #[OA\Post(
        path: '/api/v1/outbound/picklists/{id}/revert',
        summary: 'Hapus seluruh picklist (batch): semua order anggota kembali ke belum-dipick',
        description: 'Stok yang sudah ter-pick direversal ke rak asal. Ditolak seluruhnya bila ada order anggota yang sudah lanjut ke packing/pengiriman.',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Picklist'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function revert(string $id, Request $request): JsonResponse
    {
        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $this->picklistService->revert($id, $userId);
        } catch (OutboundValidationException $e) {
            return $this->errorResponse(
                'Gagal membatalkan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        } catch (Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal mengembalikan picklist.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        return $this->successResponse(null, 'Picklist dikembalikan, order kembali ke belum dipick.');
    }

    protected function attachRecommendedBins($picklist): void
    {
        $items = $picklist->items ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        $locationId = $picklist->location_id;
        $itemIds = $items->pluck('item_id')->filter()->unique()->values()->all();

        $stocks = $this->picklistRepository->recommendedBinStocks($itemIds, $locationId);

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

    /**
     * Tombol A "Alihkan Tugas" — TAHAN alokasi pick.
     * DELETE /api/v1/picklists/{id}/assignment
     */
    public function unassign(string $id, \Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'reason_code' => ['required', 'string', 'in:SALAH_TAP,SHIFT_HABIS,SAKIT,KENDALA_TEKNIS,LAINNYA'],
            'reason_note' => ['nullable', 'string', 'max:500'],
            'new_assignee_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $picklist = $this->picklistService->unassign(
            $id,
            (string) $request->user()->id,
            \App\Enums\UnassignReasonEnum::from($validated['reason_code']),
            $validated['reason_note'] ?? null,
            $validated['new_assignee_id'] ?? null,
        );

        return $this->successResponse(
            $picklist,
            $validated['new_assignee_id'] ?? false
                ? 'Tugas picking berhasil dialihkan.'
                : 'Assignment picking berhasil dibatalkan. Dokumen kembali ke antrian.',
        );
    }

    /**
     * Tombol B "Reset & Alihkan" — reverse alokasi pick + audit.
     * POST /api/v1/picklists/{id}/assignment/reset
     */
    public function resetAssignment(string $id, \Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'reason_note' => ['required', 'string', 'min:10', 'max:500'],
            'new_assignee_id' => ['nullable', 'string', 'exists:users,id'],
        ]);

        $picklist = $this->picklistService->resetAssignmentDestructive(
            $id,
            (string) $request->user()->id,
            $validated['reason_note'],
            $validated['new_assignee_id'] ?? null,
        );

        return $this->successResponse(
            $picklist,
            'Picklist berhasil di-reset. Semua alokasi dikembalikan.',
        );
    }
}
