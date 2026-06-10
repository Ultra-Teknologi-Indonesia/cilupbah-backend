<?php

namespace Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchase\Services\PurchaseBillService;
use Modules\Purchase\Http\Requests\StorePurchaseBillRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Purchase Bills', description: 'API Endpoints for Purchase Bills')]
class PurchaseBillController extends Controller
{
    public function __construct(
        protected PurchaseBillService $billService
    ) {}

    #[OA\Get(
        path: '/api/v1/purchase/bills',
        summary: 'Get list of purchase bills',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[supplier_id]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->billService->getAllPaginated($limit), 'Daftar bill berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/purchase/bills',
        summary: 'Create a new purchase bill',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['supplier_id', 'location_id', 'bill_date', 'created_by', 'items'],
            properties: [
                new OA\Property(property: 'supplier_id', type: 'string'),
                new OA\Property(property: 'location_id', type: 'string'),
                new OA\Property(property: 'purchase_order_id', type: 'string'),
                new OA\Property(property: 'bill_date', type: 'string', format: 'date'),
                new OA\Property(property: 'due_date', type: 'string', format: 'date'),
                new OA\Property(property: 'created_by', type: 'string'),
                new OA\Property(property: 'items', type: 'array', items: new OA\Items(
                    properties: [
                        new OA\Property(property: 'item_id', type: 'string'),
                        new OA\Property(property: 'qty', type: 'integer'),
                        new OA\Property(property: 'unit_price', type: 'number'),
                    ]
                )),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Bill berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StorePurchaseBillRequest $request): JsonResponse
    {
        try {
            $bill = $this->billService->createOrUpdate($request->validated());
            return $this->successResponse($bill, 'Bill berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/bills/{id}',
        summary: 'Get bill details',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Bill tidak ditemukan'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $bill = $this->billService->getById($id);
        if (! $bill) {
            return $this->errorResponse('Bill tidak ditemukan', 404);
        }
        return $this->successResponse($bill, 'Detail bill berhasil diambil');
    }

    #[OA\Delete(
        path: '/api/v1/purchase/bills',
        summary: 'Delete a draft bill',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [new OA\Property(property: 'id', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Bill berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->billService->delete($request->input('id'));
            return $this->successResponse(null, 'Bill berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/purchase/bills/unpaid',
        summary: 'Get unpaid bills',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        parameters: [new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function unpaid(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->billService->getUnpaid($limit), 'Daftar bill belum lunas');
    }

    #[OA\Get(
        path: '/api/v1/purchase/bills/overdue',
        summary: 'Get overdue bills',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        parameters: [new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function overdue(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->billService->getOverdue($limit), 'Daftar bill jatuh tempo');
    }

    #[OA\Get(
        path: '/api/v1/purchase/bills/for-return',
        summary: 'Get bills available for return',
        security: [['bearerAuth' => []]],
        tags: ['Purchase Bills'],
        parameters: [new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10))],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function forReturn(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->billService->getForReturn($limit), 'Daftar bill untuk return');
    }
}
