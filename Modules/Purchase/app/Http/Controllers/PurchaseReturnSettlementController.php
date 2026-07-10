<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseReturnSettlementService;
use Modules\Purchase\Http\Resources\PurchaseReturnSettlementResource;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Return Settlements', description: 'API Endpoints for Purchase Return Settlements')]
class PurchaseReturnSettlementController extends Controller
{
    public function __construct(
        protected PurchaseReturnSettlementService $settlementService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/return-settlements',
        summary: 'Get list of purchase return settlements',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->settlementService->getAllPaginated($limit), 'Daftar settlement berhasil diambil');
    }

    #[OA\Delete(
        path: '/api/v1/purchase/return-settlements',
        summary: 'Delete a draft return settlement',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [new OA\Property(property: 'id', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Settlement berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->settlementService->delete($request->input('id'));
            return $this->successResponse(null, 'Settlement berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/return-settlements/bills',
        summary: 'Get settlement bills',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[settlement_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function billIndex(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->settlementService->getBills($limit), 'Daftar settlement bills');
    }

    #[OA\Post(
        path: '/api/v1/purchase/return-settlements/bills',
        summary: 'Add a bill to settlement',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['settlement_id', 'bill_id', 'amount'],
            properties: [
                new OA\Property(property: 'settlement_id', type: 'string'),
                new OA\Property(property: 'bill_id', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Bill ditambahkan ke settlement'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function billStore(Request $request): JsonResponse
    {
        try {
            $bill = $this->settlementService->createBill($request->only('settlement_id', 'bill_id', 'amount'));
            return $this->successResponse($bill, 'Bill ditambahkan ke settlement', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/return-settlements/bills/{id}',
        summary: 'Get settlement bill detail',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function billShow(string $id): JsonResponse
    {
        $bill = $this->settlementService->getBillById($id);
        if (! $bill) {
            return $this->errorResponse('Settlement bill tidak ditemukan', 404);
        }
        return $this->successResponse(new PurchaseReturnSettlementResource($bill), 'Detail settlement bill');
    }

    #[OA\Get(
        path: '/api/v1/purchase/return-settlements/refunds',
        summary: 'Get settlement refunds',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[settlement_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function refundIndex(Request $request): JsonResponse
    {
        $limit = $request->query('per_page', 20);
        return $this->successPaginatedResponse($this->settlementService->getRefunds($limit), 'Daftar settlement refunds');
    }

    #[OA\Post(
        path: '/api/v1/purchase/return-settlements/refunds',
        summary: 'Add a refund to settlement',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['settlement_id', 'refund_number', 'amount', 'refund_method', 'refund_date'],
            properties: [
                new OA\Property(property: 'settlement_id', type: 'string'),
                new OA\Property(property: 'refund_number', type: 'string'),
                new OA\Property(property: 'amount', type: 'number'),
                new OA\Property(property: 'refund_method', type: 'string'),
                new OA\Property(property: 'refund_date', type: 'string', format: 'date'),
                new OA\Property(property: 'notes', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Refund ditambahkan ke settlement'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function refundStore(Request $request): JsonResponse
    {
        try {
            $refund = $this->settlementService->createRefund(
                $request->only('settlement_id', 'refund_number', 'amount', 'refund_method', 'refund_date', 'notes')
            );
            return $this->successResponse($refund, 'Refund ditambahkan ke settlement', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/return-settlements/refunds/{id}',
        summary: 'Get settlement refund detail',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Return Settlements'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Tidak ditemukan'),
        ]
    )]
    public function refundShow(string $id): JsonResponse
    {
        $refund = $this->settlementService->getRefundById($id);
        if (! $refund) {
            return $this->errorResponse('Settlement refund tidak ditemukan', 404);
        }
        return $this->successResponse(new PurchaseReturnSettlementResource($refund), 'Detail settlement refund');
    }
}
