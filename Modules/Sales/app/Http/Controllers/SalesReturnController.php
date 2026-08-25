<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Http\Requests\AcceptSalesReturnRequest;
use Modules\Sales\Http\Requests\ChannelRejectSalesReturnRequest;
use Modules\Sales\Http\Requests\CompleteSalesReturnRequest;
use Modules\Sales\Http\Requests\ExportReturnChannelOnlineRequest;
use Modules\Sales\Http\Requests\RejectSalesReturnRequest;
use Modules\Sales\Http\Requests\SalesReturnReportRequest;
use Modules\Sales\Http\Requests\StoreSalesReturnRequest;
use Modules\Sales\Http\Resources\SalesReturnAppealResource;
use Modules\Sales\Http\Resources\SalesReturnReportResource;
use Modules\Sales\Http\Resources\SalesReturnResource;
use Modules\Sales\Services\SalesReturnChannelActionService;
use Modules\Sales\Services\SalesReturnDetailSyncService;
use Modules\Sales\Services\SalesReturnService;
use Modules\Sales\Services\SalesReturnTrackingSyncService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Returns', description: 'API Endpoints for Sales Returns')]
class SalesReturnController extends Controller
{
    public function __construct(
        protected SalesReturnService $returnService,
        protected SalesReturnChannelActionService $channelActionService,
        protected SalesReturnTrackingSyncService $trackingSyncService,
        protected SalesReturnDetailSyncService $detailSyncService,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/returns',
        summary: 'Get list of sales returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 200)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 200, description: 'Alias kompatibilitas untuk per_page')),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[source]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel_shop_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[reason]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[reason_category]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('per_page', $request->integer('limit', 20)), 1), 200);
        $returns = $this->returnService->getAllPaginated($limit);

        return $this->successPaginatedResponse($returns, 'Daftar sales return berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/unprocessed',
        summary: 'Get unprocessed marketplace returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', example: '-created_at')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 200)),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', maximum: 200, description: 'Alias kompatibilitas untuk per_page')),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[channel_shop_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[reason]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[reason_category]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function unprocessed(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('per_page', $request->integer('limit', 20)), 1), 200);
        $returns = $this->returnService->getUnprocessedMarketplace($limit);

        return $this->successPaginatedResponse($returns, 'Daftar marketplace return yang belum diproses');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/filter-options',
        summary: 'Get dynamic sales return filter options',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        responses: [
            new OA\Response(response: 200, description: 'Reasons and marketplace shops available for filtering'),
        ]
    )]
    public function filterOptions(): JsonResponse
    {
        $reasons = \Illuminate\Support\Facades\DB::table('sales_returns')
            ->where('source', 'marketplace')
            ->where(function ($query): void {
                $query->whereNotNull('channel_reason_code')
                    ->orWhereNotNull('channel_reason_text')
                    ->orWhereNotNull('reason');
            })
            ->get(['channel_reason_code', 'channel_reason_text', 'reason'])
            ->map(function ($row): ?array {
                $value = trim((string) ($row->channel_reason_code ?: $row->channel_reason_text ?: $row->reason ?: ''));
                $label = trim((string) ($row->channel_reason_text ?: $row->reason ?: $row->channel_reason_code ?: ''));

                if ($value === '' || $label === '') {
                    return null;
                }

                return ['value' => $value, 'label' => $label];
            })
            ->filter()
            ->unique('value')
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $shops = \Illuminate\Support\Facades\DB::table('channel_shops as shops')
            ->join('channels', 'channels.id', '=', 'shops.channel_id')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('sales_returns')
                    ->where('sales_returns.source', 'marketplace')
                    ->whereColumn('sales_returns.channel_shop_id', 'shops.shop_id');
            })
            ->get([
                'shops.shop_id as value',
                'shops.shop_name as label',
                'channels.code as channel',
                'channels.name as channel_name',
            ])
            ->unique(fn ($shop): string => $shop->channel . '|' . $shop->value)
            ->sortBy(fn ($shop): string => strtolower($shop->channel_name . ' ' . $shop->label))
            ->values();

        return $this->successResponse([
            'reasons' => $reasons,
            'shops' => $shops,
        ], 'Opsi filter sales return berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/{id}',
        summary: 'Get sales return details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        return $this->successResponse(new SalesReturnResource($return), 'Detail sales return berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns',
        summary: 'Create a sales return (with or without order/invoice)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string', nullable: true),
                new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad20700aa53cd1aeaf6a31f7'),
                new OA\Property(property: 'source', type: 'string', enum: ['manual', 'marketplace'], example: 'manual'),
                new OA\Property(property: 'customer_name', type: 'string'),
                new OA\Property(property: 'reason', type: 'string'),
                new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'qty'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
                        new OA\Property(property: 'qty', type: 'integer', example: 2),
                        new OA\Property(property: 'condition', type: 'string', enum: ['GOOD', 'DAMAGE']),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Sales return berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->create($request->validated());

        return $this->successResponse(new SalesReturnResource($return), 'Sales return berhasil dibuat', 201);
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/accept',
        summary: 'Accept a return (stock masuk ke gudang)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'warehouse_staff'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return diterima, Inbound GRN dibuat'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function accept(string $id, AcceptSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->accept($id, $request->only('processed_by', 'items'));

        return $this->successResponse(new SalesReturnResource($return), 'Return diterima, Inbound GRN dibuat');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/reject',
        summary: 'Reject a return (stock tidak berubah)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'warehouse_staff'),
                new OA\Property(property: 'reason', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return ditolak'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function reject(string $id, RejectSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->reject($id, $request->only('processed_by', 'reason'));

        return $this->successResponse(new SalesReturnResource($return), 'Return ditolak');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/complete',
        summary: 'Mark return as complete',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['processed_by'],
            properties: [
                new OA\Property(property: 'processed_by', type: 'string', example: 'admin'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Return selesai'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function complete(string $id, CompleteSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->complete($id, $request->only('processed_by'));

        return $this->successResponse(new SalesReturnResource($return), 'Return selesai');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/sync-tracking',
        summary: 'Tarik ulang nomor resi ekspedisi retur dari marketplace',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function syncTracking(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        $this->trackingSyncService->syncOne($return);

        return $this->successResponse(new SalesReturnResource($this->returnService->getById($id)), 'Sinkronisasi resi retur selesai');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/sync-detail',
        summary: 'Tarik keputusan marketplace, alasan, refund, selisih ongkir, dan riwayat banding dari channel',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function syncDetail(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        $this->detailSyncService->syncOne($return);

        return $this->successResponse(new SalesReturnResource($this->returnService->getById($id)), 'Sinkronisasi detail retur selesai');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/{id}/appeals',
        summary: 'Riwayat proses/banding retur dari marketplace',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function appeals(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        return $this->successResponse(
            SalesReturnAppealResource::collection($this->returnService->getAppeals($return)),
            'Riwayat banding retur berhasil diambil'
        );
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/channel-accept',
        summary: 'Setujui retur di marketplace (channel online)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
            new OA\Response(response: 422, description: 'Retur bukan dari marketplace atau gagal diteruskan ke channel'),
        ]
    )]
    public function channelAccept(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        $this->channelActionService->acceptForChannel($return);

        return $this->successResponse(new SalesReturnResource($this->returnService->getById($id)), 'Retur berhasil disetujui di marketplace');
    }

    #[OA\Post(
        path: '/api/v1/sales/returns/{id}/channel-reject',
        summary: 'Tolak/dispute retur di marketplace (channel online)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['reason_id'],
            properties: [
                new OA\Property(property: 'reason_id', type: 'string', example: 'NOT_AS_DESCRIBED'),
                new OA\Property(property: 'note', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
            new OA\Response(response: 422, description: 'Retur bukan dari marketplace atau gagal diteruskan ke channel'),
        ]
    )]
    public function channelReject(string $id, ChannelRejectSalesReturnRequest $request): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        $validated = $request->validated();

        $this->channelActionService->rejectForChannel($return, $validated['reason_id'], $validated['note'] ?? null);

        return $this->successResponse(new SalesReturnResource($this->returnService->getById($id)), 'Retur berhasil ditolak di marketplace');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/{id}/channel-reject-reasons',
        summary: 'Daftar alasan tolak yang valid dari marketplace untuk retur ini',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Return tidak ditemukan'),
        ]
    )]
    public function channelRejectReasons(string $id): JsonResponse
    {
        $return = $this->returnService->getById($id);

        if (! $return) {
            return $this->errorResponse('Sales return tidak ditemukan', 404);
        }

        return $this->successResponse(
            $this->channelActionService->getRejectReasons($return),
            'Daftar alasan tolak berhasil diambil'
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/sales-returns/unpaid',
        summary: 'Get outstanding (unpaid) returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $returns = $this->returnService->getUnpaidReturns($limit);

        return $this->successPaginatedResponse($returns, 'Daftar return belum dibayar');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items',
        summary: 'Get all return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[condition]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function allItems(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $items = $this->returnService->getAllReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items/rejected',
        summary: 'Get rejected return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function rejectedItems(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $items = $this->returnService->getRejectedReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return yang ditolak');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/items/resolved',
        summary: 'Get resolved return items',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function resolvedItems(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $items = $this->returnService->getResolvedReturnItems($limit);

        return $this->successPaginatedResponse($items, 'Daftar item return yang resolved');
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/report',
        summary: 'Laporan retur detail (settlement + refund per baris)',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel_shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['manual', 'marketplace'])),
            new OA\Parameter(name: 'reason_category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'marketplace_decision', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function report(SalesReturnReportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $limit = (int) ($validated['per_page'] ?? 10);
        $rows = $this->returnService->getReportPaginated($validated, $limit);

        \App\Support\ActorName::preload($rows->pluck('processed_by'));

        return $this->successPaginatedResponse(
            SalesReturnReportResource::collection($rows),
            'Laporan retur berhasil diambil.'
        );
    }

    #[OA\Get(
        path: '/api/v1/sales/returns/report/export',
        summary: 'Unduh XLSX laporan retur detail',
        security: [['bearerAuth' => []]],
        tags: ['Sales Returns'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'location_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'channel_shop_id', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'source', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'reason_category', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'marketplace_decision', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'XLSX file stream')]
    )]
    public function reportExport(SalesReturnReportRequest $request)
    {
        ['export' => $export, 'filename' => $filename] = $this->returnService->prepareExport(
            'report',
            $request->validated(),
            $request->user()?->id,
        );

        return Excel::download($export, $filename);
    }

    public function exportChannelOnline(ExportReturnChannelOnlineRequest $request)
    {
        ['export' => $export, 'filename' => $filename] = $this->returnService->prepareExport(
            'channel_online',
            $request->validated(),
            $request->user()?->id,
        );

        return Excel::download($export, $filename);
    }
}
