<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Sales\Http\Requests\StoreSalesInvoiceRequest;
use Modules\Sales\Http\Resources\SalesInvoiceResource;
use Modules\Sales\Http\Resources\SalesPaymentResource;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Invoices', description: 'API Endpoints for Sales Invoices')]
class SalesInvoiceController extends Controller
{
    public function __construct(
        protected SalesInvoiceService $invoiceService,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/invoices',
        summary: 'Get list of sales invoices',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[location_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $invoices = $this->invoiceService->getAllPaginated($limit);

        return $this->successPaginatedResponse($invoices, 'Daftar invoice berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/invoices',
        summary: 'Create a new sales invoice',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['location_id', 'invoice_date', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string', nullable: true),
                new OA\Property(property: 'customer_name', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'invoice_date', type: 'string', format: 'date'),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'created_by', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    required: ['item_id', 'qty', 'unit_price'],
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'qty', type: 'integer'),
                        new OA\Property(property: 'unit_price', type: 'number'),
                        new OA\Property(property: 'disc_amount', type: 'number', nullable: true),
                        new OA\Property(property: 'tax_amount', type: 'number', nullable: true),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Invoice berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoiceService->createOrUpdate($request->validated());

        return $this->successResponse(new SalesInvoiceResource($invoice), 'Invoice berhasil dibuat', 201);
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/{id}',
        summary: 'Get invoice details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Invoice tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $invoice = $this->invoiceService->getById($id);

        if (! $invoice) {
            return $this->errorResponse('Invoice tidak ditemukan', 404);
        }

        return $this->successResponse(new SalesInvoiceResource($invoice), 'Detail invoice berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/unpaid',
        summary: 'Get outstanding/unpaid invoices',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $invoices = $this->invoiceService->getUnpaid($limit);

        return $this->successPaginatedResponse($invoices, 'Daftar invoice belum lunas');
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/overdue',
        summary: 'Get overdue invoices',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function overdue(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        $invoices = $this->invoiceService->getOverdue($limit);

        return $this->successPaginatedResponse($invoices, 'Daftar invoice jatuh tempo');
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/summary',
        summary: 'Get invoice summary by store/location',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function summary(): JsonResponse
    {
        $summary = $this->invoiceService->getSummary();

        return $this->successResponse($summary, 'Ringkasan invoice per lokasi');
    }

    #[OA\Get(
        path: '/api/v1/sales/invoices/for-return-wms/{contact_id}',
        summary: 'Get invoice IDs for creating sales returns',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function forReturnWms(string $contactId, Request $request): JsonResponse
    {
        $limit = (int) ($request->query('per_page') ?? $request->query('limit') ?? 20);
        $invoices = $this->invoiceService->getForReturnWms($contactId, $limit);

        return $this->successPaginatedResponse($invoices, 'Daftar invoice untuk return');
    }

    #[OA\Post(
        path: '/api/v1/sales/packlists/create-invoice',
        summary: 'Create invoice from sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id', 'created_by'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string', nullable: true),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'created_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Invoice berhasil dibuat dari order'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function createFromOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id'   => 'required|bail|uuid|exists:sales_orders,id',
            'location_id' => 'nullable|bail|uuid|exists:locations,id',
            'due_date'   => 'nullable|date',
            'created_by' => 'required|string|max:100',
        ]);

        $invoice = $this->invoiceService->createFromOrder($validated);

        return $this->successResponse(new SalesInvoiceResource($invoice), 'Invoice berhasil dibuat dari order', 201);
    }

    #[OA\Post(
        path: '/api/v1/sales/packlists/create-invoice-payment',
        summary: 'Create invoice + payment from sales order',
        security: [['bearerAuth' => []]],
        tags: ['Sales Invoices'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['order_id', 'created_by'],
            properties: [
                new OA\Property(property: 'order_id', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string', nullable: true),
                new OA\Property(property: 'due_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'payment_method', type: 'string', example: 'CASH'),
                new OA\Property(property: 'reference_no', type: 'string', nullable: true),
                new OA\Property(property: 'created_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Invoice + payment berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function createFromOrderWithPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id'       => 'required|bail|uuid|exists:sales_orders,id',
            'location_id'    => 'nullable|bail|uuid|exists:locations,id',
            'due_date'       => 'nullable|date',
            'payment_method' => 'nullable|string|max:100',
            'reference_no'   => 'nullable|string',
            'notes'          => 'nullable|string',
            'created_by'     => 'required|string|max:100',
        ]);

        $result = $this->invoiceService->createFromOrderWithPayment($validated);

        return $this->successResponse([
            'invoice' => new SalesInvoiceResource($result['invoice']),
            'payment' => new SalesPaymentResource($result['payment']),
        ], 'Invoice dan payment berhasil dibuat', 201);
    }
}
