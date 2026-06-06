<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supplier\Services\SupplierService;
use Modules\Supplier\Http\Requests\StoreSupplierRequest;
use Modules\Supplier\Http\Requests\UpdateSupplierRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Suppliers', description: 'API Endpoints for Suppliers')]
class SupplierController extends Controller
{
    public function __construct(
        protected SupplierService $supplierService
    ) {}

    #[OA\Get(
        path: '/api/v1/suppliers',
        summary: 'Get list of suppliers',
        security: [['bearerAuth' => []]],
        tags: ['Suppliers'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $suppliers = $this->supplierService->getAllPaginated($limit);

        return $this->successPaginatedResponse($suppliers, 'Daftar supplier berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/suppliers',
        summary: 'Create a new supplier',
        security: [['bearerAuth' => []]],
        tags: ['Suppliers'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name'],
            properties: [
                new OA\Property(property: 'code', type: 'string', example: 'SUP-001'),
                new OA\Property(property: 'name', type: 'string', example: 'PT Maju Jaya'),
                new OA\Property(property: 'company_name', type: 'string', example: 'PT Maju Jaya Sentosa'),
                new OA\Property(property: 'email', type: 'string', example: 'info@majujaya.com'),
                new OA\Property(property: 'phone', type: 'string', example: '021-5551234'),
                new OA\Property(property: 'address', type: 'string'),
                new OA\Property(property: 'city', type: 'string', example: 'Jakarta'),
                new OA\Property(property: 'tax_id', type: 'string', example: '01.234.567.8-901.000'),
                new OA\Property(property: 'contact_person', type: 'string'),
                new OA\Property(property: 'payment_term', type: 'string', example: 'NET30'),
                new OA\Property(property: 'notes', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Supplier berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreSupplierRequest $request): JsonResponse
    {
        try {
            $supplier = $this->supplierService->create($request->validated());
            return $this->successResponse($supplier, 'Supplier berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/suppliers/{id}',
        summary: 'Get supplier details',
        security: [['bearerAuth' => []]],
        tags: ['Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Supplier tidak ditemukan'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $supplier = $this->supplierService->getById($id);

        if (! $supplier) {
            return $this->errorResponse('Supplier tidak ditemukan', 404);
        }

        return $this->successResponse($supplier, 'Detail supplier berhasil diambil');
    }

    #[OA\Put(
        path: '/api/v1/suppliers/{id}',
        summary: 'Update a supplier',
        security: [['bearerAuth' => []]],
        tags: ['Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'email', type: 'string'),
                new OA\Property(property: 'phone', type: 'string'),
                new OA\Property(property: 'status', type: 'string', enum: ['active', 'inactive']),
            ]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Supplier berhasil diupdate'),
            new OA\Response(response: 404, description: 'Supplier tidak ditemukan'),
        ]
    )]
    public function update(UpdateSupplierRequest $request, int $id): JsonResponse
    {
        try {
            $supplier = $this->supplierService->update($id, $request->validated());
            return $this->successResponse($supplier, 'Supplier berhasil diupdate');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }

    #[OA\Delete(
        path: '/api/v1/suppliers/{id}',
        summary: 'Delete a supplier',
        security: [['bearerAuth' => []]],
        tags: ['Suppliers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Supplier berhasil dihapus'),
            new OA\Response(response: 404, description: 'Supplier tidak ditemukan'),
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->supplierService->delete($id);
            return $this->successResponse(null, 'Supplier berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 404);
        }
    }
}
