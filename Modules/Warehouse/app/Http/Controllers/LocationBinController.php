<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Warehouse\Http\Requests\StoreLocationBinRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Location Bins', description: 'API Endpoints for Warehouse Location Bins')]
#[OA\Schema(
    schema: 'LocationBin',
    title: 'Location Bin Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'floor_code', type: 'string', example: 'FL-01', nullable: true),
        new OA\Property(property: 'row_code', type: 'string', example: 'RW-02', nullable: true),
        new OA\Property(property: 'column_code', type: 'string', example: 'COL-A', nullable: true),
        new OA\Property(property: 'bin_code', type: 'string', example: 'BIN-100', nullable: true),
        new OA\Property(property: 'bin_final_code', type: 'string', example: 'FL01-RW02-COLA-BIN100', nullable: true),
        new OA\Property(property: 'max_qty', type: 'integer', example: 100, nullable: true),
        new OA\Property(property: 'is_inbound', type: 'boolean', example: false, nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StoreLocationBinRequest',
    required: ['location_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'integer', example: 1),
        new OA\Property(property: 'floor_code', type: 'string', example: 'FL-01', nullable: true),
        new OA\Property(property: 'row_code', type: 'string', example: 'RW-02', nullable: true),
        new OA\Property(property: 'column_code', type: 'string', example: 'COL-A', nullable: true),
        new OA\Property(property: 'bin_code', type: 'string', example: 'BIN-100', nullable: true),
        new OA\Property(property: 'max_qty', type: 'integer', example: 100, nullable: true),
        new OA\Property(property: 'is_inbound', type: 'boolean', example: false, nullable: true),
    ]
)]
class LocationBinController extends Controller
{
    public function __construct(
        protected LocationBinService $binService
    ) {}

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/bins',
        summary: 'Get list of bins for a location',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/LocationBin')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar bin berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(int $locationId): JsonResponse
    {
        $bins = $this->binService->getByLocation($locationId);

        return $this->successResponse($bins, 'Daftar bin berhasil diambil');
    }

    #[OA\Get(
        path: '/api/v1/locations/{locationId}/default-bin',
        summary: 'Get default bin for a location',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/LocationBin'),
                        new OA\Property(property: 'message', type: 'string', example: 'Default bin berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Default bin tidak ditemukan.')
        ]
    )]
    public function defaultBin(int $locationId): JsonResponse
    {
        $bin = $this->binService->getDefaultBin($locationId);

        if (!$bin) {
            return $this->errorResponse('Default bin tidak ditemukan.', 404);
        }

        return $this->successResponse($bin, 'Default bin berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/bins',
        summary: 'Create a new location bin',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreLocationBinRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Bin created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/LocationBin'),
                        new OA\Property(property: 'message', type: 'string', example: 'Bin berhasil dibuat.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(StoreLocationBinRequest $request): JsonResponse
    {
        try {
            $bin = $this->binService->create($request->validated());

            return $this->successResponse($bin, 'Bin berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    #[OA\Delete(
        path: '/api/v1/bins/{id}',
        summary: 'Delete a location bin',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the location bin to delete', schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Bin deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', nullable: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Bin berhasil dihapus.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Bin tidak ditemukan.')
        ]
    )]
    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->binService->delete($id);

            if (!$deleted) {
                return $this->errorResponse('Bin tidak ditemukan.', 404);
            }

            return $this->successResponse(null, 'Bin berhasil dihapus.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
