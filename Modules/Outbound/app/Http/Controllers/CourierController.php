<?php

namespace Modules\Outbound\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Outbound\Http\Resources\CourierResource;
use Modules\Outbound\Services\CourierService;
use Modules\Outbound\Http\Requests\CreateCourierRequest;
use Modules\Outbound\Http\Requests\UpdateCourierRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Outbound - Couriers', description: 'API Endpoints for Courier management')]
#[OA\Schema(
    schema: 'Courier',
    title: 'Courier Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'name', type: 'string', example: 'JNE'),
        new OA\Property(property: 'code', type: 'string', example: 'jne'),
        new OA\Property(property: 'tracking_url', type: 'string', nullable: true),
        new OA\Property(property: 'logo_url', type: 'string', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean'),
        new OA\Property(property: 'supported_shipment_types', type: 'array', nullable: true, items: new OA\Items(type: 'string')),
        new OA\Property(property: 'metadata', type: 'object', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
class CourierController extends Controller
{
    public function __construct(
        protected CourierService $courierService,
    ) {}

    #[OA\Get(
        path: '/api/v1/outbound/couriers',
        summary: 'Get paginated couriers',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'filter[is_active]', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'filter[q]', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    #[OA\Get(
        path: '/api/v1/outbound/couriers/tenant/{tenantId}',
        summary: 'Get couriers belonging to a specific tenant',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        parameters: [
            new OA\Parameter(name: 'tenantId', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 10)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function byTenant(string $tenantId, Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->courierService->getByTenant($tenantId, $limit);

        $data->through(fn ($courier) => new CourierResource($courier));

        return $this->successResponse($data);
    }

    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('per_page', $request->query('limit', 10));
        $data = $this->courierService->getAllPaginated($limit);

        $data->through(fn ($courier) => new CourierResource($courier));

        return $this->successResponse($data);
    }

    #[OA\Get(
        path: '/api/v1/outbound/couriers/all',
        summary: 'Get all active couriers (no pagination)',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function all(): JsonResponse
    {
        $data = $this->courierService->getAll();

        return $this->successResponse(CourierResource::collection($data));
    }

    #[OA\Post(
        path: '/api/v1/outbound/couriers',
        summary: 'Create a new courier',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'code'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'JNE'),
                    new OA\Property(property: 'code', type: 'string', example: 'jne'),
                    new OA\Property(property: 'tracking_url', type: 'string', nullable: true),
                    new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                    new OA\Property(property: 'supported_shipment_types', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Created'),
            new OA\Response(response: 422, description: 'Validation Error'),
        ]
    )]
    public function store(CreateCourierRequest $request): JsonResponse
    {
        $courier = $this->courierService->create($request->validated());

        return $this->successResponse(new CourierResource($courier), null, 201);
    }

    #[OA\Get(
        path: '/api/v1/outbound/couriers/{id}',
        summary: 'Get courier detail',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $courier = $this->courierService->getById($id);

        if (!$courier) {
            return $this->errorResponse('Courier tidak ditemukan.', 404);
        }

        return $this->successResponse(new CourierResource($courier));
    }

    #[OA\Put(
        path: '/api/v1/outbound/couriers/{id}',
        summary: 'Update courier',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'tracking_url', type: 'string', nullable: true),
                    new OA\Property(property: 'logo_url', type: 'string', nullable: true),
                    new OA\Property(property: 'is_active', type: 'boolean'),
                    new OA\Property(property: 'supported_shipment_types', type: 'array', items: new OA\Items(type: 'string')),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Success'),
            new OA\Response(response: 404, description: 'Not Found'),
        ]
    )]
    public function update(string $id, UpdateCourierRequest $request): JsonResponse
    {
        $courier = $this->courierService->update($id, $request->validated());

        return $this->successResponse(new CourierResource($courier));
    }

    #[OA\Delete(
        path: '/api/v1/outbound/couriers/{id}',
        summary: 'Delete courier',
        security: [['bearerAuth' => []]],
        tags: ['Outbound - Couriers'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Success'),
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $this->courierService->delete($id);

        return $this->successResponse(null, 'Courier berhasil dihapus.');
    }
}
