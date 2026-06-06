<?php

namespace Modules\Inbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inbound\Services\InboundService;
use Modules\Inbound\Http\Requests\StoreInboundRequest;
use Modules\Inbound\Http\Requests\ReceiveInboundRequest;
use Modules\Inbound\Http\Requests\AutoPutawayRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Inbounds', description: 'API Endpoints for Inbounds')]
#[OA\Schema(
    schema: 'Inbound',
    title: 'Inbound Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'transaction_number', type: 'string', example: 'INB-20260604-0001'),
        new OA\Property(property: 'reference_number', type: 'string', example: 'PO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['PURCHASE_ORDER', 'SALES_RETURN', 'TRANSIT_IN'], example: 'PURCHASE_ORDER'),
        new OA\Property(property: 'status', type: 'string', example: 'DRAFT'),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date-time', example: '2026-06-05T00:00:00Z'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', example: '2026-06-04T12:00:00Z'),
    ]
)]
#[OA\Schema(
    schema: 'StoreInboundRequest',
    required: ['location_id', 'type', 'expected_date', 'created_by', 'items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'reference_number', type: 'string', example: 'PO-2026-0001', nullable: true),
        new OA\Property(property: 'type', type: 'string', enum: ['PURCHASE_ORDER', 'SALES_RETURN', 'TRANSIT_IN'], example: 'PURCHASE_ORDER'),
        new OA\Property(property: 'expected_date', type: 'string', format: 'date', example: '2026-06-05'),
        new OA\Property(property: 'created_by', type: 'string', example: 'admin'),
        new OA\Property(
            property: 'items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['item_id', 'expected_qty'],
                properties: [
                    new OA\Property(property: 'item_id', type: 'integer', example: 10),
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
                    new OA\Property(property: 'inbound_item_id', type: 'integer', example: 1),
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
    schema: 'AutoPutawayRequest',
    required: ['location_id', 'inbound_ids', 'created_by', 'putaway_items'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'inbound_ids', type: 'array', items: new OA\Items(type: 'integer', example: 1)),
        new OA\Property(property: 'created_by', type: 'string', example: 'warehouse_admin'),
        new OA\Property(
            property: 'putaway_items',
            type: 'array',
            items: new OA\Items(
                type: 'object',
                required: ['item_id', 'destination_bin_id', 'qty'],
                properties: [
                    new OA\Property(property: 'item_id', type: 'integer', example: 10),
                    new OA\Property(property: 'destination_bin_id', type: 'integer', example: 5),
                    new OA\Property(property: 'qty', type: 'integer', example: 50),
                    new OA\Property(property: 'batch_no', type: 'string', example: 'BATCH-001', nullable: true),
                    new OA\Property(property: 'serial_no', type: 'string', example: 'SN-001', nullable: true)
                ]
            )
        )
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
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Inbound')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar inbound berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $inbounds = $this->inboundService->getAllPaginated($limit);

        return $this->successPaginatedResponse($inbounds, 'Daftar inbound berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/inbounds/{id}',
        summary: 'Get inbound details',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the inbound', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Inbound'),
                        new OA\Property(property: 'message', type: 'string', example: 'Detail Inbound berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Dokumen Inbound tidak ditemukan')
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $inbound = $this->inboundService->getById($id);

        if (!$inbound) {
            return $this->errorResponse('Dokumen Inbound tidak ditemukan', 404);
        }

        return $this->successResponse($inbound, 'Detail Inbound berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/inbounds',
        summary: 'Create a draft inbound',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreInboundRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Draft Inbound created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Inbound'),
                        new OA\Property(property: 'message', type: 'string', example: 'Draft Inbound berhasil dibuat')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
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
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Post(
        path: '/api/v1/inbounds/{id}/receive',
        summary: 'Process receiving for inbound',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the inbound to receive', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ReceiveInboundRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Penerimaan Inbound berhasil diproses',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Inbound'),
                        new OA\Property(property: 'message', type: 'string', example: 'Penerimaan Inbound berhasil diproses')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Inbound not found'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function receive(int $id, ReceiveInboundRequest $request): JsonResponse
    {
        try {
            $inbound = $this->inboundService->receive($id, $request->validated());
            return $this->successResponse($inbound, 'Penerimaan Inbound berhasil diproses');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/inbounds/received-items',
        summary: 'Get list of received items',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar barang diterima berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function receivedItems(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $items = $this->inboundService->getReceivedItemsPaginated($limit);

        return $this->successPaginatedResponse($items, 'Daftar barang diterima berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/inbounds/auto-putaway',
        summary: 'Execute auto-putaway',
        security: [['bearerAuth' => []]],
        tags: ['Inbounds'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/AutoPutawayRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Auto-putaway berhasil dieksekusi',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Auto-putaway berhasil dieksekusi')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error'),
            new OA\Response(response: 500, description: 'Server Error')
        ]
    )]
    public function autoPutaway(AutoPutawayRequest $request): JsonResponse
    {
        try {
            $results = $this->inboundService->autoPutaway($request->validated());
            return $this->successResponse($results, 'Auto-putaway berhasil dieksekusi', 200);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
