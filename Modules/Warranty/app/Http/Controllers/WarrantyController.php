<?php

namespace Modules\Warranty\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Warranty\Services\WarrantyService;
use Modules\Warranty\Http\Requests\StoreWarrantyRequest;
use Modules\Warranty\Http\Requests\UpdateWarrantyRequest;
use Modules\Warranty\Http\Resources\WarrantyResource;
use App\Traits\ApiResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Warranty')]
class WarrantyController extends Controller
{
    use ApiResponse;

    protected WarrantyService $service;

    public function __construct(WarrantyService $service)
    {
        $this->service = $service;
    }

    #[OA\Get(
        path: '/api/v1/warranties',
        operationId: 'getWarranties',
        summary: 'Get all warranties',
        tags: ['Warranty'],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success')
        ]
    )]
    public function index(): JsonResponse
    {
        $limit = request('per_page', 20);
        $warranties = $this->service->getAllPaginated($limit);

        return $this->successPaginatedResponse(WarrantyResource::collection($warranties));
    }

    #[OA\Post(
        path: '/api/v1/warranties',
        operationId: 'storeWarranty',
        summary: 'Create new warranty',
        tags: ['Warranty'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_variant_id', 'duration_months', 'start_date', 'end_date'],
                properties: [
                    new OA\Property(property: 'product_variant_id', type: 'string'),
                    new OA\Property(property: 'order_id', type: 'string', nullable: true),
                    new OA\Property(property: 'serial_no', type: 'string', nullable: true),
                    new OA\Property(property: 'duration_months', type: 'integer'),
                    new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'end_date', type: 'string', format: 'date'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created')
        ]
    )]
    public function store(StoreWarrantyRequest $request): JsonResponse
    {
        $warranty = $this->service->create($request->validated());

        return $this->successResponse(new WarrantyResource($warranty), 'Warranty created successfully', 201);
    }

    #[OA\Get(
        path: '/api/v1/warranties/{id}',
        operationId: 'getWarrantyById',
        summary: 'Get warranty by ID',
        tags: ['Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not found')
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $warranty = $this->service->getById($id);

        if (!$warranty) {
            return $this->errorResponse('Warranty not found', 404);
        }

        return $this->successResponse(new WarrantyResource($warranty));
    }

    #[OA\Put(
        path: '/api/v1/warranties/{id}',
        operationId: 'updateWarranty',
        summary: 'Update warranty',
        tags: ['Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string'),
                    new OA\Property(property: 'notes', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Updated'),
            new OA\Response(response: 404, description: 'Not found')
        ]
    )]
    public function update(UpdateWarrantyRequest $request, string $id): JsonResponse
    {
        $warranty = $this->service->getById($id);

        if (!$warranty) {
            return $this->errorResponse('Warranty not found', 404);
        }

        $warranty = $this->service->update($id, $request->validated());

        return $this->successResponse(new WarrantyResource($warranty), 'Warranty updated successfully');
    }

    #[OA\Delete(
        path: '/api/v1/warranties/{id}',
        operationId: 'deleteWarranty',
        summary: 'Delete warranty',
        tags: ['Warranty'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found')
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $warranty = $this->service->getById($id);

        if (!$warranty) {
            return $this->errorResponse('Warranty not found', 404);
        }

        $this->service->delete($id);

        return $this->successResponse(null, 'Warranty deleted successfully');
    }
}
