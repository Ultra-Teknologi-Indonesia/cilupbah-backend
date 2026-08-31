<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\LocationService;
use Modules\Warehouse\Http\Requests\StoreLocationRequest;
use Modules\Warehouse\Http\Requests\UpdateLocationRequest;
use Modules\Warehouse\Http\Resources\LocationResource;
use Modules\Warehouse\Models\Location;

use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Locations', description: 'API Endpoints for Warehouse Locations')]
#[OA\Schema(
    schema: 'Location',
    title: 'Location Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_code', type: 'string', example: 'WH-01'),
        new OA\Property(property: 'location_name', type: 'string', example: 'Main Warehouse'),
        new OA\Property(property: 'location_type', type: 'string', example: 'WAREHOUSE'),
        new OA\Property(property: 'address', type: 'string', example: 'Jl. Jenderal Sudirman No. 1', nullable: true),
        new OA\Property(property: 'village_id', type: 'string', example: '3173031006', nullable: true, description: 'ID kelurahan (Region). Provinsi/kota/kecamatan otomatis tersambung lewat relasi village.'),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_pos', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'village', type: 'object', nullable: true, description: 'Relasi kelurahan beserta kecamatan, kota, dan provinsi (village.district.city.province)'),
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
        new OA\Property(property: 'village_id', type: 'string', example: '3173031006', nullable: true, description: 'Cukup kirim ID kelurahan; provinsi/kota/kecamatan otomatis tersambung.'),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_pos', type: 'boolean', example: false, nullable: true),
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
        new OA\Property(property: 'village_id', type: 'string', example: '3173031006', nullable: true, description: 'Cukup kirim ID kelurahan; provinsi/kota/kecamatan otomatis tersambung.'),
        new OA\Property(property: 'post_code', type: 'string', example: '10220', nullable: true),
        new OA\Property(property: 'is_warehouse', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_multi_origin', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'default_warehouse_user', type: 'string', example: 'admin@warehouse.com', nullable: true),
        new OA\Property(property: 'is_active', type: 'boolean', example: true, nullable: true),
        new OA\Property(property: 'is_fbl', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_tcb', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_fbs', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'is_pos', type: 'boolean', example: false, nullable: true),
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
        $locations = $this->locationService->getAllPaginated();
        $locations->through(fn (Location $location) => new LocationResource($location));

        return $this->successPaginatedResponse($locations, 'Daftar lokasi berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/locations/pos',
        summary: 'Get all locations that have POS outlets',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10)),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Full-text search by location name', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, description: 'Sort by location_name | created_at | location_code', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Location')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar lokasi POS berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function pos(Request $request): JsonResponse
    {
        $locations = $this->locationService->getPosLocations();
        $locations->through(fn (Location $location) => new LocationResource($location));

        return $this->successPaginatedResponse($locations, 'Daftar lokasi POS berhasil diambil');
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

            return $this->successResponse(new LocationResource($location), 'Lokasi berhasil dibuat.', 201);
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal menyimpan.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }

    #[OA\Get(
        path: '/api/v1/locations/{id}',
        summary: 'Get location details',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string'))
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
    public function show(string $id): JsonResponse
    {
        $location = $this->locationService->getById($id);

        if (!$location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse(new LocationResource($location), 'Detail lokasi berhasil diambil');
    }

    #[OA\Put(
        path: '/api/v1/locations/{id}',
        summary: 'Update an existing location',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location to update', schema: new OA\Schema(type: 'string'))
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
    public function update(UpdateLocationRequest $request, string $id): JsonResponse
    {
        try {
            $location = $this->locationService->update($id, $request->validated());
        } catch (\DomainException $e) {
            return $this->errorResponse(
                'Gagal memperbarui.',
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }

        if (!$location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse(new LocationResource($location), 'Lokasi berhasil diperbarui.');
    }

    #[OA\Delete(
        path: '/api/v1/locations/{id}',
        summary: 'Delete a location',
        security: [['bearerAuth' => []]],
        tags: ['Locations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location to delete', schema: new OA\Schema(type: 'string'))
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
    public function destroy(string $id): JsonResponse
    {
        try {
            $deleted = $this->locationService->delete($id);

            if (!$deleted) {
                return $this->errorResponse('Lokasi tidak ditemukan.', 404);
            }

            return $this->successResponse(null, 'Lokasi berhasil dihapus.');
        } catch (\DomainException $e) {
            return $this->errorResponse(
                $e->getMessage(),
                422,
                ['detail' => $e->getMessage()],
                'Aksi tidak dapat diproses',
            );
        }
    }
}
