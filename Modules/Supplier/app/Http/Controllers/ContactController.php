<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Supplier\Services\ContactService;
use Modules\Supplier\Http\Requests\StoreContactRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Contacts', description: 'API Endpoints for unified Contacts (customers + suppliers)')]
class ContactController extends Controller
{
    public function __construct(
        protected ContactService $contactService
    ) {}

    #[OA\Get(
        path: '/api/v1/contacts',
        summary: 'Get all contacts',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[status]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'filter[type]', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['CUSTOMER', 'SUPPLIER', 'BOTH'])),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->contactService->getAllPaginated($limit), 'Daftar kontak berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/contacts',
        summary: 'Create/edit a contact',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['name', 'type'],
            properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(property: 'company_name', type: 'string'),
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'phone', type: 'string'),
                new OA\Property(property: 'address', type: 'string'),
                new OA\Property(property: 'city', type: 'string'),
                new OA\Property(property: 'province', type: 'string'),
                new OA\Property(property: 'postal_code', type: 'string'),
                new OA\Property(property: 'tax_id', type: 'string'),
                new OA\Property(property: 'contact_person', type: 'string'),
                new OA\Property(property: 'payment_term', type: 'string'),
                new OA\Property(property: 'type', type: 'string', enum: ['CUSTOMER', 'SUPPLIER', 'BOTH']),
                new OA\Property(property: 'category_id', type: 'string'),
            ]
        )),
        responses: [
            new OA\Response(response: 201, description: 'Contact berhasil dibuat'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(StoreContactRequest $request): JsonResponse
    {
        try {
            $contact = $this->contactService->create($request->validated());
            return $this->successResponse($contact, 'Contact berhasil dibuat', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/contacts/{id}',
        summary: 'Get contact details',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 404, description: 'Contact tidak ditemukan'),
        ]
    )]
    public function update(StoreContactRequest $request, string $id): JsonResponse
    {
        try {
            $contact = $this->contactService->update($id, $request->validated());
            return $this->successResponse($contact, 'Contact berhasil diperbarui');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(string $id): JsonResponse
    {
        $contact = $this->contactService->getById($id);
        if (! $contact) {
            return $this->errorResponse('Contact tidak ditemukan', 404);
        }
        return $this->successResponse($contact, 'Detail contact berhasil diambil');
    }

    #[OA\Delete(
        path: '/api/v1/contacts',
        summary: 'Delete a contact',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['id'],
            properties: [new OA\Property(property: 'id', type: 'string')]
        )),
        responses: [
            new OA\Response(response: 200, description: 'Contact berhasil dihapus'),
            new OA\Response(response: 500, description: 'Server Error'),
        ]
    )]
    public function destroy(Request $request): JsonResponse
    {
        try {
            $this->contactService->delete($request->input('id'));
            return $this->successResponse(null, 'Contact berhasil dihapus');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/contacts/customers',
        summary: 'Get all customers',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function customers(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->contactService->getCustomers($limit), 'Daftar customer berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/contacts/suppliers',
        summary: 'Get all suppliers/vendors',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function suppliers(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->contactService->getSuppliers($limit), 'Daftar supplier berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/contacts/customers-suppliers',
        summary: 'Get contacts that are both customer and supplier',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[search]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function customersSuppliers(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        return $this->successPaginatedResponse($this->contactService->getCustomersAndSuppliers($limit), 'Daftar customer-supplier berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/contact/category',
        summary: 'Get all contact categories',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function categories(): JsonResponse
    {
        $categories = $this->contactService->getCategories();
        return $this->successResponse($categories, 'Daftar kategori kontak berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/contact/account-payable',
        summary: 'Get account payable options for contacts',
        security: [['bearerAuth' => []]],
        tags: ['Contacts'],
        responses: [new OA\Response(response: 200, description: 'Successful operation')]
    )]
    public function accountPayableOptions(): JsonResponse
    {
        $options = $this->contactService->getAccountPayableOptions();
        return $this->successResponse($options, 'Daftar akun hutang berhasil diambil');
    }
}
