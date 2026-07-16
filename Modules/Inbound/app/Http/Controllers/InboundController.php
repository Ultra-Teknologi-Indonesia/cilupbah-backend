<?php

namespace Modules\Inbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inbound\Services\InboundService;
use Modules\Inbound\Http\Requests\StoreInboundRequest;
use Modules\Inbound\Http\Requests\ReceiveInboundRequest;
use Modules\Inbound\Http\Requests\PutawayRequest;
use Modules\Inbound\Http\Requests\AutoPutawayRequest;
use Modules\Inbound\Http\Requests\AssignInboundRequest;
use Modules\Inbound\Http\Requests\ScanPutawayRequest;
use Modules\Inbound\Http\Requests\UnassignInboundRequest;
use Modules\Inbound\Http\Requests\ResetInboundAssignmentRequest;
use App\Enums\UnassignReasonEnum;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inbounds', description: 'API Endpoints for Inbounds')]
#[OA\Tag(name: 'Inbounds - Assignment', description: 'Admin assign tugas ke pekerja gudang')]
#[OA\Tag(name: 'Inbounds - QR Scan', description: 'Pekerja scan QR barang & lokasi rak untuk putaway')]
#[OA\Schema(
    schema: 'Inbound',
    title: 'Inbound Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'transaction_number', type: 'string', example: 'INB-20260604-0001'),
        new OA\Property(property: 'reference_number', type: 'string', example: 'PO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['PURCHASE_ORDER', 'SALES_RETURN', 'TRANSIT_IN', 'CONSIGNMENT'], example: 'PURCHASE_ORDER'),
        new OA\Property(property: 'source_type', type: 'string', example: 'purchase_order', nullable: true),
        new OA\Property(property: 'source_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e', nullable: true),
        new OA\Property(property: 'status', type: 'string', enum: ['DRAFT', 'PARTIAL', 'RECEIVED', 'PUTAWAY_IN_PROGRESS', 'COMPLETED', 'CANCELLED'], example: 'DRAFT'),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date-time', example: '2026-06-05T00:00:00Z'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StoreInboundRequest',
    required: ['location_id', 'type', 'expected_date', 'created_by', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'reference_number', type: 'string', example: 'PO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['PURCHASE_ORDER', 'SALES_RETURN', 'TRANSIT_IN', 'CONSIGNMENT'], example: 'PURCHASE_ORDER'),
        new OA\Property(property: 'source_type', type: 'string', example: 'purchase_order', nullable: true),
        new OA\Property(property: 'source_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e', nullable: true),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date', example: '2026-06-05'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['item_id', 'expected_qty'],
                properties: [
                    new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                    new OA\Property(property: 'expected_qty', type: 'integer', example: 50)
                ]
            )
        )
    ]
)]
#[OA\Schema(
    schema: 'ReceiveInboundRequest',
    required: ['received_by', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'received_by', type: 'string', example: 'warehouse_staff'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['inbound_item_id', 'qty'],
                properties: [
                    new OA\Property(property: 'inbound_item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                    new OA\Property(property: 'qty', type: 'integer', example: 50),
                    new OA\Property(property: 'condition', type: 'string', enum: ['GOOD', 'DAMAGE'], example: 'GOOD', nullable: true),
                    new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
                    new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true)
                ]
            )
        )
    ]
)]
#[OA\Schema(
    schema: 'PutawayRequest',
    required: ['created_by', 'putaway_items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'created_by', type: 'string', example: 'warehouse_admin'),
        new OA\Property(
            property: 'putaway_items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['inbound_item_id', 'destination_bin_id', 'qty'],
                properties: [
                    new OA\Property(property: 'inbound_item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                    new OA\Property(property: 'destination_bin_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
                    new OA\Property(property: 'qty', type: 'integer', example: 50),
                    new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
                    new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true)
                ]
            )
        )
    ]
)]
#[OA\Schema(
    schema: 'AutoPutawayRequest',
    required: ['created_by'],
    type: 'object',
    properties: [
        new OA\Property(property: 'created_by', type: 'string', example: 'warehouse_admin'),
    ]
)]
#[OA\Schema(
    schema: 'InboundItem',
    title: 'Inbound Item Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', description: 'UUID primary key — digunakan sebagai QR code label barang', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'inbound_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'expected_qty', type: 'integer', example: 50),
        new OA\Property(property: 'received_qty', type: 'integer', example: 0),
        new OA\Property(property: 'putaway_qty', type: 'integer', example: 0),
        new OA\Property(property: 'discrepancy_qty', type: 'integer', example: 0),
        new OA\Property(property: 'discrepancy_note', type: 'string', nullable: true),
        new OA\Property(property: 'condition', type: 'string', enum: ['GOOD', 'DAMAGE'], example: 'GOOD'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'InboundAssignment',
    title: 'Inbound Assignment Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'inbound_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'assigned_to', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'assigned_by', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'status', type: 'string', enum: ['PENDING', 'IN_PROGRESS', 'COMPLETED'], example: 'PENDING'),
        new OA\Property(property: 'notes', type: 'string', example: 'Prioritas tinggi', nullable: true),
        new OA\Property(property: 'started_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'completed_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property: 'worker',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                new OA\Property(property: 'name', type: 'string', example: 'Budi Pekerja'),
                new OA\Property(property: 'email', type: 'string', example: 'budi@warehouse.com'),
            ],
            nullable: true
        ),
        new OA\Property(
            property: 'assigner',
            type: 'object',
            properties: [
                new OA\Property(property: 'id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
                new OA\Property(property: 'name', type: 'string', example: 'Admin Gudang'),
                new OA\Property(property: 'email', type: 'string', example: 'admin@warehouse.com'),
            ],
            nullable: true
        ),
    ]
)]
#[OA\Schema(
    schema: 'AssignInboundRequest',
    required: ['assigned_to'],
    type: 'object',
    properties: [
        new OA\Property(property: 'assigned_to', type: 'string', description: 'User ID pekerja', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'notes', type: 'string', description: 'Catatan untuk pekerja', example: 'Prioritas tinggi', nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'ScanPutawayRequest',
    required: ['inbound_item_id', 'bin_id', 'qty'],
    type: 'object',
    properties: [
        new OA\Property(property: 'inbound_item_id', type: 'string', description: 'Scan 1: UUID dari label barang (= inbound_items.id)', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'bin_id', type: 'string', description: 'Scan 2: UUID dari rak tujuan (= location_bins.id)', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
        new OA\Property(property: 'qty', type: 'integer', description: 'Jumlah barang yang di-putaway', example: 10),
    ]
)]
class InboundController extends Controller
{
    public function __construct(
        protected InboundService $inboundService
    ) {}

    #[OA\Get(
        path: '/api/v1/inbounds',
        summary: 'Get list of inbounds',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[type]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $inbounds = $this->inboundService->getAllPaginated($limit);

        return $this->successPaginatedResponse($inbounds, 'Daftar inbound berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}',
        summary: 'Get inbound details',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Dokumen Inbound tidak ditemukan')
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $inbound = $this->inboundService->getById($id);

        if (! $inbound) {
            return $this->errorResponse('Dokumen Inbound tidak ditemukan', 404);
        }

        // Fase 2: sertakan participants + edit_lock untuk panel Sesi Aktif FE web.
        $inbound->load(['participants.user:id,name']);

        $participantsData = $inbound->participants->map(function ($p) use ($inbound) {
            $receiptCount = \Modules\Inbound\Models\InboundReceipt::query()
                ->join('inbound_items as i', 'inbound_receipts.inbound_item_id', '=', 'i.id')
                ->where('i.inbound_id', $inbound->id)
                ->where('inbound_receipts.received_by', $p->user_id)
                ->count();

            $receiptQtySum = \Modules\Inbound\Models\InboundReceipt::query()
                ->join('inbound_items as i', 'inbound_receipts.inbound_item_id', '=', 'i.id')
                ->where('i.inbound_id', $inbound->id)
                ->where('inbound_receipts.received_by', $p->user_id)
                ->sum('inbound_receipts.qty');

            return [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? 'staff',
                'role' => $p->role,
                'status' => $p->status,
                'joined_at' => $p->joined_at?->toIso8601String(),
                'completed_at' => $p->completed_at?->toIso8601String(),
                'withdrawn_at' => $p->withdrawn_at?->toIso8601String(),
                'withdraw_reason' => $p->withdraw_reason,
                'receipts_count' => $receiptCount,
                'receipts_qty_sum' => (int) $receiptQtySum,
            ];
        })->values();

        $activeParticipants = $participantsData
            ->where('status', \Modules\Inbound\Models\InboundParticipant::STATUS_ACTIVE)
            ->values();

        $editLock = [
            'locked' => $activeParticipants->isNotEmpty(),
            'reason' => $activeParticipants->isNotEmpty() ? 'mobile_session_active' : null,
            'active_participants' => $activeParticipants
                ->map(fn ($p) => ['user_id' => $p['user_id'], 'name' => $p['name']])
                ->values(),
        ];

        $data = $inbound->toArray();
        $data['participants'] = $participantsData;
        $data['edit_lock'] = $editLock;

        return $this->successResponse($data, 'Detail Inbound berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}/pdf',
        summary: 'Cetak dokumen Penerimaan sebagai PDF (A4 portrait)',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'PDF stream',
                content: new OA\MediaType(mediaType: 'application/pdf'),
            ),
            new OA\Response(response: 404, description: 'Dokumen Inbound tidak ditemukan')
        ]
    )]
    public function pdf(string $id)
    {
        $inbound = $this->inboundService->getById($id);

        if (! $inbound) {
            return $this->errorResponse('Dokumen Inbound tidak ditemukan', 404);
        }

        try {
            $filename = "{$inbound->transaction_number}.pdf";

            $pdf = Pdf::loadView('inbound::pdf.receipt', [
                'inbound' => $inbound,
            ])->setPaper('a4', 'portrait');

            return $pdf->stream($filename);
        } catch (\Throwable $e) {
            report($e);
            return $this->errorResponse(
                'Gagal membuat PDF penerimaan.',
                500,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}/items',
        summary: 'Get paginated inbound items with SKU filter/sort',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Full-text search SKU + nama produk', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'sku|-sku|expected_qty|-expected_qty|received_qty|-received_qty|putaway_qty|-putaway_qty|created_at|-created_at', schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function items(string $id, Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 20);
        $items = $this->inboundService->getPaginatedItems($id, $perPage);
        return $this->successPaginatedResponse($items, 'Daftar item Inbound berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/inbounds',
        summary: 'Create a draft inbound (GRN)',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/StoreInboundRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Draft Inbound berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function store(StoreInboundRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->createDraft($request->validated());
            return $this->successResponse($inbound, 'Draft Inbound berhasil dibuat', 201);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/receive',
        summary: 'Receive items for inbound',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ReceiveInboundRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Penerimaan berhasil diproses'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function receive(string $id, ReceiveInboundRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->receive($id, $request->validated());
            return $this->successResponse($inbound, 'Penerimaan Inbound berhasil diproses');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public function correctReceivedLine(string $id, string $itemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $inbound = $this->inboundService->correctReceivedLine($id, $itemId, $validated['qty'] ?? null, $userId);

            return $this->successResponse($inbound, 'Penerimaan berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses penerimaan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    public function correctReceivedLines(string $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|string',
            'items.*.qty' => 'nullable|integer|min:1',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $inbound = $this->inboundService->correctReceivedLines($id, $validated['items'], $userId);

            return $this->successResponse($inbound, 'Penerimaan berhasil dikoreksi.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses penerimaan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Patch(
        path: '/api/v1/inbounds/{id}/items/{itemId}/received-qty',
        summary: 'Set jumlah diterima aktual pada satu baris (boleh naik/turun)',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'itemId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['qty'],
                properties: [new OA\Property(property: 'qty', type: 'integer', example: 3)]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Jumlah diterima diperbarui'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function setReceivedQty(string $id, string $itemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qty' => 'required|integer|min:0',
            '_expected_updated_at' => 'nullable|string',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $inbound = $this->inboundService->setReceivedQty(
                $id,
                $itemId,
                (int) $validated['qty'],
                $userId,
                $validated['_expected_updated_at'] ?? null,
            );

            return $this->successResponse($inbound, 'Jumlah diterima berhasil diperbarui.');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memproses penerimaan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/bulk-cancel',
        summary: 'Batalkan (hapus) beberapa penerimaan sekaligus',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids'],
                properties: [new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'string'))]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Penerimaan terpilih diproses'),
            new OA\Response(response: 422, description: 'Validasi gagal'),
        ]
    )]
    public function bulkCancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|string|distinct|exists:inbounds,id',
        ]);

        try {
            $userId = (string) ($request->user()->id ?? 'system');
            $result = $this->inboundService->cancelMany($validated['ids'], $userId);

            return $this->successResponse($result, 'Penerimaan terpilih diproses.');
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
        path: '/api/v1/inbounds/{id}/close-receiving',
        summary: 'Close receiving (mark discrepancy and move to putaway)',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['closed_by'],
                properties: [new OA\Property(property: 'closed_by', type: 'string', example: 'admin')]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Receiving ditutup'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function closeReceiving(string $id, Request $request): JsonResponse
    {
        $request->validate(['closed_by' => 'required|string|max:100']);

        try {
            $inbound = $this->inboundService->closeReceiving($id, $request->closed_by);
            return $this->successResponse($inbound, 'Receiving ditutup, discrepancy tercatat');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/putaway',
        summary: 'Manual putaway — assign items to specific bins',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PutawayRequest')),
        responses: [
            new OA\Response(response: 200, description: 'Putaway berhasil diproses'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function putaway(string $id, PutawayRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->processPutaway($id, $request->validated());
            return $this->successResponse($inbound, 'Putaway berhasil diproses');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/auto-putaway',
        summary: 'Auto putaway — system assigns bins automatically',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AutoPutawayRequest')
        ),
        responses: [
            new OA\Response(response: 200, description: 'Auto-putaway berhasil'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function autoPutaway(string $id, Request $request): JsonResponse
    {
        $request->validate(['created_by' => 'required|string|max:100']);

        try {
            $inbound = $this->inboundService->autoPutaway($id, $request->created_by);
            return $this->successResponse($inbound, 'Auto-putaway berhasil dieksekusi');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Get(
        path: '/api/v1/inbounds/received-items',
        summary: 'Get list of received items (receipts)',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function receivedItems(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $items = $this->inboundService->getReceivedItemsPaginated($limit);

        return $this->successPaginatedResponse($items, 'Daftar barang diterima berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}/pending-putaway',
        summary: 'Get items pending putaway for an inbound',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function pendingPutaway(string $id): JsonResponse
    {
        $items = $this->inboundService->getItemsPendingPutaway($id);
        return $this->successResponse($items, 'Items pending putaway');
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/assign',
        summary: 'Assign inbound to a worker',
        description: 'Admin gudang assign dokumen inbound ke pekerja. Pekerja akan melihat tugas ini di endpoint my-assignments.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - Assignment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Inbound ID', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AssignInboundRequest')),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Berhasil assign',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Inbound berhasil di-assign'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InboundAssignment'),
                ])
            ),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Inbound sudah COMPLETED/CANCELLED')
        ]
    )]
    public function assign(string $id, AssignInboundRequest $request): JsonResponse
    {
        try {
            $assignment = $this->inboundService->assignWorker(
                $id,
                $request->assigned_to,
                $request->user()->id,
                $request->notes
            );
            return $this->successResponse(
                $assignment->load('worker:id,name,email', 'assigner:id,name,email'),
                'Inbound berhasil di-assign',
                201
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Tombol A "Alihkan Tugas" — TAHAN progress.
     * DELETE /api/v1/inbounds/{id}/assignment
     */
    public function unassign(string $id, UnassignInboundRequest $request): JsonResponse
    {
        $inbound = $this->inboundService->unassignWorker(
            $id,
            (string) $request->user()->id,
            UnassignReasonEnum::from($request->reason_code),
            $request->reason_note,
            $request->new_assignee_id,
        );

        return $this->successResponse(
            $inbound,
            $request->new_assignee_id
                ? 'Tugas berhasil dialihkan.'
                : 'Assignment berhasil dibatalkan. Dokumen kembali ke antrian web.',
        );
    }

    /**
     * Tombol B "Reset & Alihkan" — reverse received_qty + audit.
     * POST /api/v1/inbounds/{id}/assignment/reset
     */
    public function resetAssignment(string $id, ResetInboundAssignmentRequest $request): JsonResponse
    {
        $inbound = $this->inboundService->resetAssignment(
            $id,
            (string) $request->user()->id,
            $request->reason_note,
            $request->new_assignee_id,
        );

        return $this->successResponse(
            $inbound,
            'Penerimaan berhasil di-reset. Semua qty diterima dikembalikan ke 0.',
        );
    }

    /**
     * DEPRECATED — alias untuk markParticipantDone (backward-compat mobile lama).
     */
    public function markReceived(string $id): JsonResponse
    {
        return $this->markParticipantDone($id);
    }

    /**
     * Mobile per-user "Tandai Selesai" (fase 2).
     * POST /api/v1/inbounds/{id}/mark-done
     */
    public function markParticipantDone(string $id): JsonResponse
    {
        $inbound = $this->inboundService->markParticipantDone(
            $id,
            (string) request()->user()->id,
        );

        return $this->successResponse(
            $inbound,
            'Anda ditandai Selesai. Sesi ditutup penuh setelah semua staff Selesai.',
        );
    }

    /**
     * Mobile eksplisit join sesi (tanpa scan) — fase 2.
     * POST /api/v1/inbounds/{id}/join
     */
    public function joinSession(string $id): JsonResponse
    {
        $inbound = $this->inboundService->joinSession(
            $id,
            (string) request()->user()->id,
        );

        return $this->successResponse($inbound, 'Anda bergabung dalam sesi penerimaan.');
    }

    /**
     * Mobile self-leave (belum sempat input) — fase 2.
     * POST /api/v1/inbounds/{id}/leave
     */
    public function leaveSession(string $id): JsonResponse
    {
        $inbound = $this->inboundService->leaveSession(
            $id,
            (string) request()->user()->id,
        );

        return $this->successResponse($inbound, 'Anda keluar dari sesi penerimaan.');
    }

    /**
     * Admin tarik participant dari web (fase 2).
     * POST /api/v1/inbounds/{id}/participants/{userId}/withdraw
     */
    public function withdrawParticipant(string $id, string $userId, Request $request): JsonResponse
    {
        $inbound = $this->inboundService->withdrawParticipant(
            $id,
            $userId,
            (string) request()->user()->id,
            $request->input('reason_note'),
        );

        return $this->successResponse($inbound, 'Peserta berhasil ditarik dari sesi.');
    }

    /**
     * F5: Buat Inbound susulan dari PO (delivery bertahap).
     * POST /api/v1/purchase-orders/{poId}/receive-additional
     */
    public function receiveAdditional(string $poId): JsonResponse
    {
        $po = \Modules\Purchase\Models\PurchaseOrder::find($poId);
        if (! $po) {
            return $this->errorResponse('PO tidak ditemukan', 404);
        }

        $inbound = $this->inboundService->createDraftFromPO(
            po: $po,
            createdBy: (string) request()->user()->id,
            isAdditional: true,
        );

        return $this->successResponse($inbound, 'Penerimaan susulan berhasil dibuat.');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}/assignments',
        summary: 'Get assignments for an inbound',
        description: 'Lihat semua pekerja yang di-assign ke dokumen inbound tertentu.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - Assignment'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Inbound ID', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar assignment',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InboundAssignment')),
                ])
            ),
        ]
    )]
    public function assignments(string $id): JsonResponse
    {
        $assignments = $this->inboundService->getAssignments($id);
        return $this->successResponse($assignments, 'Daftar assignment berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/my-assignments',
        summary: 'Get current user assignments',
        description: 'Pekerja melihat daftar tugas inbound yang di-assign ke dia. Bisa filter berdasarkan status.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - Assignment'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'Filter berdasarkan status', schema: new OA\Schema(type: 'string', enum: ['PENDING', 'IN_PROGRESS', 'COMPLETED']))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar assignment saya',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string'),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/InboundAssignment')),
                ])
            ),
        ]
    )]
    public function myAssignments(Request $request): JsonResponse
    {
        $assignments = $this->inboundService->getMyAssignments(
            $request->user()->id,
            $request->query('status'),
            (int) $request->query('per_page', 20),
            $request->query('search'),
            $request->query('sort', '-created_at'),
        );
        return $this->successPaginatedResponse($assignments, 'Daftar assignment Anda');
    }

    #[OA\Post(
        path: '/api/v1/inbounds/assignments/{assignmentId}/start',
        summary: 'Start working on an assignment',
        description: 'Pekerja memulai tugas. Status berubah dari PENDING ke IN_PROGRESS. Hanya pemilik assignment yang bisa start.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - Assignment'],
        parameters: [
            new OA\Parameter(name: 'assignmentId', in: 'path', required: true, description: 'Assignment ID', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Assignment dimulai',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Assignment dimulai'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InboundAssignment'),
                ])
            ),
            new OA\Response(response: 500, description: 'Bukan assignment Anda / sudah dimulai')
        ]
    )]
    public function startAssignment(string $assignmentId, Request $request): JsonResponse
    {
        try {
            $assignment = $this->inboundService->startAssignment($assignmentId, $request->user()->id);
            return $this->successResponse($assignment, 'Assignment dimulai');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[OA\Get(
        path: '/api/v1/inbounds/scan/{qrCode}',
        summary: 'Scan QR — lookup inbound item by SKU/barcode (atau UUID untuk backward compat)',
        description: 'Pekerja scan QR code pada label barang. Isi QR biasanya SKU/barcode. Query param inbound_id (opsional tapi disarankan) menyempitkan lookup ke inbound yang sedang dibuka user — supaya SKU yang muncul di banyak inbound aktif tidak salah pilih.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - QR Scan'],
        parameters: [
            new OA\Parameter(name: 'qrCode', in: 'path', required: true, description: 'Isi QR: SKU / barcode / UUID inbound_item.id', schema: new OA\Schema(type: 'string', example: 'DENIM-GREY-17PM')),
            new OA\Parameter(name: 'inbound_id', in: 'query', required: false, description: 'Scope lookup ke inbound tertentu (UUID). Tanpa ini, backend jatuh ke "latest" — bisa salah inbound.', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Item ditemukan',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Item ditemukan'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InboundItem'),
                ])
            ),
            new OA\Response(response: 404, description: 'QR Code tidak ditemukan')
        ]
    )]
    public function scanQr(string $qrCode, Request $request): JsonResponse
    {
        try {
            $item = $this->inboundService->lookupByQr($qrCode, $request->query('inbound_id'));
            return $this->successResponse($item, 'Item ditemukan');
        } catch (\Exception $e) {
            return $this->errorResponse(
                'Gagal memindai.',
                404,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/scan-putaway',
        summary: 'Scan QR barang + scan QR rak → putaway & update inventory',
        description: 'Pekerja scan 2x: (1) scan QR label barang (= inbound_items.id UUID), (2) scan QR lokasi rak (= location_bins.id UUID). Sistem memindahkan stock dari inbound bin ke rak tujuan dan update inventory. Jika semua item sudah putaway, inbound otomatis COMPLETED dan assignment auto-selesai.',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds - QR Scan'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ScanPutawayRequest')),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Putaway berhasil, stock diperbarui',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'message', type: 'string', example: 'Putaway berhasil, stock diperbarui'),
                    new OA\Property(property: 'data', ref: '#/components/schemas/InboundItem'),
                ])
            ),
            new OA\Response(response: 404, description: 'QR rak tidak ditemukan'),
            new OA\Response(response: 422, description: 'Validation Error — inbound_item_id / bin_id bukan UUID'),
            new OA\Response(response: 500, description: 'Qty melebihi pending / inbound belum RECEIVED')
        ]
    )]
    public function scanPutaway(ScanPutawayRequest $request): JsonResponse
    {
        try {
            $item = $this->inboundService->scanPutaway(
                $request->inbound_item_id,
                $request->bin_id,
                $request->qty,
                $request->user()->id
            );
            return $this->successResponse($item, 'Putaway berhasil, stock diperbarui');
        } catch (\Exception $e) {
            $code = str_contains($e->getMessage(), 'tidak ditemukan') ? 404 : 500;
            return $this->errorResponse(
                'Gagal memindai.',
                $code,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}/barcodes',
        summary: 'Download barcode PDF for all products in an inbound receipt',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF stream', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(response: 404, description: 'Not found'),
        ]
    )]
    public function downloadBarcodes(string $id)
    {
        return $this->inboundService->downloadBarcodes($id);
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/cancel',
        summary: 'Cancel an inbound',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Inbound dibatalkan'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function cancel(string $id): JsonResponse
    {
        try {
            $inbound = $this->inboundService->cancel($id);
            return $this->successResponse($inbound, 'Inbound berhasil dibatalkan');
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
