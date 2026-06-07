<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Warehouse\Http\Requests\GenerateLocationBinRequest;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Location Bins', description: 'API Endpoints for Warehouse Location Bins')]
#[OA\Schema(
    schema: 'LocationBin',
    title: 'Location Bin Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
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
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string'))
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
    public function index(string $locationId): JsonResponse
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
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string'))
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
    public function defaultBin(string $locationId): JsonResponse
    {
        $bin = $this->binService->getDefaultBin($locationId);

        if (!$bin) {
            return $this->errorResponse('Default bin tidak ditemukan.', 404);
        }

        return $this->successResponse($bin, 'Default bin berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/locations/{locationId}/bins/preview',
        summary: 'Preview location bins generation',
        security: [['bearerAuth' => []]],
        tags: ['Location Bins'],
        parameters: [
            new OA\Parameter(name: 'locationId', in: 'path', required: true, description: 'ID of the location', schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['floor_code', 'qty_floor', 'row_code', 'qty_row', 'column_code', 'qty_column', 'bin_code', 'qty_bin'],
                properties: [
                    new OA\Property(property: 'floor_code', type: 'string', example: 'FL'),
                    new OA\Property(property: 'qty_floor', type: 'integer', example: 1),
                    new OA\Property(property: 'row_code', type: 'string', example: 'RW'),
                    new OA\Property(property: 'qty_row', type: 'integer', example: 2),
                    new OA\Property(property: 'column_code', type: 'string', example: 'C'),
                    new OA\Property(property: 'qty_column', type: 'integer', example: 3),
                    new OA\Property(property: 'bin_code', type: 'string', example: 'B'),
                    new OA\Property(property: 'qty_bin', type: 'integer', example: 4),
                    new OA\Property(property: 'max_qty', type: 'integer', example: 100)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Preview generated successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object'),
                        new OA\Property(property: 'message', type: 'string', example: 'Preview berhasil di-generate')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function preview(GenerateLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $preview = $this->binService->previewMassGenerate($request->validated());

            return $this->successResponse(
                $preview, 
                "Preview berhasil di-generate.", 
                200
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
