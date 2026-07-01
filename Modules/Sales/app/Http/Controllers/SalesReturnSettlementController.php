<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesReturnSettlementService;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Return Settlements', description: 'API Endpoints for Return Settlements')]
class SalesReturnSettlementController extends Controller
{
    public function __construct(
        protected SalesReturnSettlementService $service,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/return-settlements',
        summary: 'Get list of return settlements',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $settlements = $this->service->getAllPaginated($limit);

        return $this->successPaginatedResponse($settlements, 'Daftar return settlement berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/return-settlements',
        summary: 'Create a new return settlement',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['return_id'],
            properties: [
                new OA\Property(property: 'return_id', type: 'string'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Return settlement berhasil dibuat'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'return_id' => 'required|bail|uuid|exists:sales_returns,id',
            'notes'     => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()?->email;

        $settlement = $this->service->create($validated);

        return $this->successResponse($settlement, 'Return settlement berhasil dibuat', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/return-settlements/{id}',
        summary: 'Get return settlement detail',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $settlement = $this->service->getById($id);

        if (! $settlement) {
            return $this->errorResponse('Return settlement tidak ditemukan', 404);
        }

        return $this->successResponse($settlement);
    }

    #[OA\Post(
        path: '/api/v1/sales/return-settlements/{id}/confirm',
        summary: 'Confirm a draft settlement',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Settlement berhasil dikonfirmasi'),
        ]
    )]
    public function confirm(string $id): JsonResponse
    {
        $settlement = $this->service->confirm($id);

        return $this->successResponse($settlement, 'Settlement berhasil dikonfirmasi');
    }

    #[OA\Post(
        path: '/api/v1/sales/return-settlements/{id}/complete',
        summary: 'Complete a confirmed settlement',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Settlement berhasil diselesaikan'),
        ]
    )]
    public function complete(string $id): JsonResponse
    {
        $settlement = $this->service->complete($id);

        return $this->successResponse($settlement, 'Settlement berhasil diselesaikan');
    }

    #[OA\Delete(
        path: '/api/v1/sales/return-settlements/{id}',
        summary: 'Delete a draft return settlement',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Return settlement berhasil dihapus'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->service->delete($id);

        return $this->successResponse(null, 'Return settlement berhasil dihapus');
    }

    #[OA\Get(
        path: '/api/v1/sales/return-settlements/invoices',
        summary: 'Get list of settlement invoices',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[settlement_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function invoiceIndex(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $invoices = $this->service->getInvoices($limit);

        return $this->successPaginatedResponse($invoices, 'Daftar settlement invoice berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/return-settlements/invoices',
        summary: 'Create settlement invoice',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['settlement_id', 'invoice_id', 'amount'],
            properties: [
                new OA\Property(property: 'settlement_id', type: 'string'),
                new OA\Property(property: 'invoice_id', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Settlement invoice berhasil dibuat'),
        ]
    )]
    public function invoiceStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settlement_id' => 'required|bail|uuid|exists:sales_return_settlements,id',
            'invoice_id'    => 'required|bail|uuid|exists:sales_invoices,id',
            'amount'        => 'required|numeric|min:0.01',
        ]);

        $entry = $this->service->createInvoice($validated);

        return $this->successResponse($entry, 'Settlement invoice berhasil dibuat', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/return-settlements/invoices/{id}',
        summary: 'Get settlement invoice details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function invoiceShow(string $id): JsonResponse
    {
        $entry = $this->service->getInvoiceById($id);

        if (! $entry) {
            return $this->errorResponse('Settlement invoice tidak ditemukan', 404);
        }

        return $this->successResponse($entry);
    }

    #[OA\Get(
        path: '/api/v1/sales/return-settlements/refunds',
        summary: 'Get list of settlement refunds',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[settlement_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function refundIndex(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $refunds = $this->service->getRefunds($limit);

        return $this->successPaginatedResponse($refunds, 'Daftar refund berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/return-settlements/refunds',
        summary: 'Create settlement refund',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['settlement_id', 'refund_number', 'amount', 'refund_method', 'refund_date'],
            properties: [
                new OA\Property(property: 'settlement_id', type: 'string'),
                new OA\Property(property: 'refund_number', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
                new OA\Property(property: 'refund_method', type: 'string'),
                new OA\Property(property: 'refund_date', type: 'string', format: 'date'),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Refund berhasil dibuat'),
        ]
    )]
    public function refundStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'settlement_id' => 'required|bail|uuid|exists:sales_return_settlements,id',
            'refund_number'  => 'required|string|max:50',
            'amount'         => 'required|numeric|min:0.01',
            'refund_method'  => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::in(
                app(\Modules\Sales\Services\SalesReturnSettingService::class)->allowedRefundMethods()
            )],
            'refund_date'    => 'required|date',
            'notes'          => 'nullable|string',
        ]);

        $refund = $this->service->createRefund($validated);

        return $this->successResponse($refund, 'Refund berhasil dibuat', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/return-settlements/refunds/{id}',
        summary: 'Get refund details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function refundShow(string $id): JsonResponse
    {
        $refund = $this->service->getRefundById($id);

        if (! $refund) {
            return $this->errorResponse('Refund tidak ditemukan', 404);
        }

        return $this->successResponse($refund);
    }

    #[OA\Delete(
        path: '/api/v1/sales/return-settlements/invoices/{id}',
        summary: 'Delete a settlement invoice',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Settlement invoice berhasil dihapus'),
        ]
    )]
    public function invoiceDestroy(string $id): JsonResponse
    {
        $this->service->deleteInvoice($id);

        return $this->successResponse(null, 'Settlement invoice berhasil dihapus');
    }

    #[OA\Delete(
        path: '/api/v1/sales/return-settlements/refunds/{id}',
        summary: 'Delete a settlement refund',
        security: [['bearerAuth' => []]],
        tags: ['Sales Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Refund berhasil dihapus'),
        ]
    )]
    public function refundDestroy(string $id): JsonResponse
    {
        $this->service->deleteRefund($id);

        return $this->successResponse(null, 'Refund berhasil dihapus');
    }
}
