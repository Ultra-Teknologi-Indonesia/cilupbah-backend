<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\LocationService;
use Modules\Warehouse\Http\Requests\StoreLocationRequest;
use Modules\Warehouse\Http\Requests\UpdateLocationRequest;
use Modules\Warehouse\Models\Location;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Locations', description: 'API Endpoints for Warehouse Locations')]
#[OA\Schema(
    schema: 'Location',
    title: 'Location Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'location_code', type: 'string', example: 'WH-01'),
        new OA\Property(property: 'location_name', type: 'string', example: 'Main Warehouse'),
        new OA\Property(property: 'location_type', type: 'string', example: 'WAREHOUSE'),
        new OA\Property(property: 'address', type: 'string', example: 'Jl. Jenderal Sudirman No. 1', nullable: true),
        new OA\Property(property: 'area', type: 'string', example: 'Central', nullable: true),
        new OA\Property(property: 'city', type: 'string', example: 'Jakarta', nullable: true),
        new OA\Property(property: 'province', type: 'string', example: 'DKI Jakarta', nullable: true),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'zones', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationZone')),
    ]
)]
#[OA\Schema(
    schema: 'LocationLayoutRack',
    type: 'object',
    required: ['floor_code', 'row_code', 'column_code', 'bin_code', 'bin_final_code'],
    properties: [
        new OA\Property(property: 'floor_code', type: 'string', example: 'FL1'),
        new OA\Property(property: 'row_code', type: 'string', example: 'RW1'),
        new OA\Property(property: 'column_code', type: 'string', example: 'C1'),
        new OA\Property(property: 'bin_code', type: 'string', example: 'B1'),
        new OA\Property(property: 'bin_final_code', type: 'string', example: 'FL1-RW1-C1-B1'),
        new OA\Property(property: 'max_qty', type: 'integer', example: 100),
    ]
)]
#[OA\Schema(
    schema: 'LocationLayoutZone',
    type: 'object',
    required: ['zone_code', 'racks'],
    properties: [
        new OA\Property(property: 'zone_code', type: 'string', example: 'Z-A'),
        new OA\Property(property: 'zone_name', type: 'string', example: 'Zona Kosmetik', nullable: true),
        new OA\Property(property: 'racks', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationLayoutRack')),
    ]
)]
#[OA\Schema(
    schema: 'StoreLocationRequest',
    required: ['location_code', 'location_name', 'location_type'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_code', type: 'string', example: 'WH-01'),
        new OA\Property(property: 'location_name', type: 'string', example: 'Main Warehouse'),
        new OA\Property(property: 'location_type', type: 'string', example: 'WAREHOUSE'),
        new OA\Property(property: 'address', type: 'string', example: 'Jl. Jenderal Sudirman No. 1', nullable: true),
        new OA\Property(property: 'area', type: 'string', example: 'Central', nullable: true),
        new OA\Property(property: 'city', type: 'string', example: 'Jakarta', nullable: true),
        new OA\Property(property: 'province', type: 'string', example: 'DKI Jakarta', nullable: true),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'layout', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationLayoutZone'), nullable: true),
    ]
)]
#[OA\Schema(
    schema: 'UpdateLocationRequest',
    type: 'object',
    properties: [
        new OA\Property(property: 'location_code', type: 'string', example: 'WH-01'),
        new OA\Property(property: 'location_name', type: 'string', example: 'Main Warehouse'),
        new OA\Property(property: 'location_type', type: 'string', example: 'WAREHOUSE'),
        new OA\Property(property: 'address', type: 'string', example: 'Jl. Jenderal Sudirman No. 1', nullable: true),
        new OA\Property(property: 'area', type: 'string', example: 'Central', nullable: true),
        new OA\Property(property: 'city', type: 'string', example: 'Jakarta', nullable: true),
        new OA\Property(property: 'province', type: 'string', example: 'DKI Jakarta', nullable: true),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'layout', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationLayoutZone'), nullable: true),
    ]
)]
class LocationController extends Controller
{
    public function __construct(
        protected LocationService $locationService
    ) {}

    #[OA\Get(
        path: '/api/v1/locations',
        summary: 'Get list of locations',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Location')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar lokasi berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $locations = $this->locationService->getAllPaginated($limit);

        return $this->successPaginatedResponse($locations, 'Daftar lokasi berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/locations',
        summary: 'Create a new location',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreLocationRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Location created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
                        new OA\Property(property: 'message', type: 'string', example: 'Lokasi berhasil dibuat.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(StoreLocationRequest $request): JsonResponse
    {
        try {
            $location = $this->locationService->create($request->validated());

            return $this->successResponse($location, 'Lokasi berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Get(
        path: '/api/v1/locations/{id}',
        summary: 'Get location details',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/Location'),
                        new OA\Property(property: 'message', type: 'string', example: 'Detail lokasi berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan.')
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $location = $this->locationService->getById($id);

        if (!$location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse($location, 'Detail lokasi berhasil diambil');
    }

    #[OA\Put(
        path: '/api/v1/locations/{id}',
        summary: 'Update an existing location',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location to update', schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/UpdateLocationRequest')
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Location updated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', nullable: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Lokasi berhasil diperbarui.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan.'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function update(UpdateLocationRequest $request, int $id): JsonResponse
    {
        $updated = $this->locationService->update($id, $request->validated());

        if (!$updated) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse(null, 'Lokasi berhasil diperbarui.');
    }

    #[OA\Delete(
        path: '/api/v1/locations/{id}',
        summary: 'Delete a location',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Location deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', nullable: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Lokasi berhasil dihapus.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Lokasi tidak ditemukan.')
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->locationService->delete($id);

            if (!$deleted) {
                return $this->errorResponse('Lokasi tidak ditemukan.', 404);
            }

            return $this->successResponse(null, 'Lokasi berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
