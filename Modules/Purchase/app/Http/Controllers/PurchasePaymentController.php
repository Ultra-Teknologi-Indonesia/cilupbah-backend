<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchasePaymentService;
use Modules\Purchase\Http\Requests\StorePurchasePaymentRequest;
use Modules\Purchase\Http\Resources\PurchasePaymentResource;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Payments', description: 'API Endpoints for Purchase Bill Payments')]
class PurchasePaymentController extends Controller
{
    public function __construct(
        protected PurchasePaymentService $paymentService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/payments',
        summary: 'Get list of purchase payments',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Payments'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[purchase_bill_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->paymentService->getAllPaginated($limit), 'Daftar payment berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/purchase/payments',
        summary: 'Create a purchase payment',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Payments'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['purchase_bill_id', 'amount', 'payment_date', 'payment_method', 'created_by'],
            properties: [
                new OA\Property(property: 'purchase_bill_id', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
                new OA\Property(property: 'payment_date', type: 'string', format: 'date'),
                new OA\Property(property: 'payment_method', type: 'string'),
                new OA\Property(property: 'reference_no', type: 'string'),
                new OA\Property(property: 'notes', type: 'string'),
                new OA\Property(property: 'created_by', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Payment berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StorePurchasePaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->paymentService->create($request->validated());
            return $this->successResponse($payment, 'Payment berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/payments/{id}',
        summary: 'Get payment details',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Payments'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
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
        return $this->successResponse(new PurchasePaymentResource($payment), 'Detail payment berhasil diambil');
    }

    #[OA\Delete(
        path: '/api/v1/purchase/payments',
        summary: 'Delete a payment and reverse bill paid amount',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Payments'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [new OA\Property(property: 'id', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Payment berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->paymentService->delete($request->input('id'));
            return $this->successResponse(null, 'Payment berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
