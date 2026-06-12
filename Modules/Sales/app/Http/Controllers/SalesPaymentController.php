<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Services\SalesPaymentService;
use Modules\Sales\Http\Requests\StoreSalesPaymentRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Sales Payments', description: 'API Endpoints for Sales Payments')]
class SalesPaymentController extends Controller
{
    public function __construct(
        protected SalesPaymentService $paymentService,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/payments',
        summary: 'Get list of invoice payments',
        security: [['bearerAuth' => []]],
        tags: ['Sales Payments'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[sales_invoice_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[payment_method]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $payments = $this->paymentService->getAllPaginated($limit);

        return $this->successPaginatedResponse($payments, 'Daftar payment berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/sales/payments',
        summary: 'Create a new payment',
        security: [['bearerAuth' => []]],
        tags: ['Sales Payments'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['sales_invoice_id', 'amount', 'payment_date', 'payment_method', 'created_by'],
            properties: [
                new OA\Property(property: 'sales_invoice_id', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
                new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
                new OA\Property(property: 'payment_method', type: 'string'),
                new OA\Property(property: 'reference_no', type: 'string', nullable: true),
                new OA\Property(property: 'notes', type: 'string', nullable: true),
                new OA\Property(property: 'created_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Payment berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSalesPaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->paymentService->create($request->validated());
            return $this->successResponse($payment, 'Payment berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/sales/payments/{id}',
        summary: 'Get payment details',
        security: [['bearerAuth' => []]],
        tags: ['Sales Payments'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Payment tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $payment = $this->paymentService->getById($id);

        if (! $payment) {
            return $this->errorResponse('Payment tidak ditemukan', 404);
        }

        return $this->successResponse($payment, 'Detail payment berhasil diambil');
    }

    #[OA\Delete(
        path: '/api/v1/sales/payments',
        summary: 'Delete a payment',
        security: [['bearerAuth' => []]],
        tags: ['Sales Payments'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [
                new OA\Property(property: 'id', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Payment berhasil dihapus'),
            new OA\Response(response: 404, description: 'Payment tidak ditemukan'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['id' => 'required|bail|uuid|exists:sales_payments,id']);

        try {
            $this->paymentService->delete($request->input('id'));
            return $this->successResponse(null, 'Payment berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
