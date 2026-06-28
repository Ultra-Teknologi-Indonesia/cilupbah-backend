<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Http\Requests\GenerateLocationBinRequest;
use Modules\Warehouse\Http\Requests\StoreLocationBinRequest;
use Modules\Warehouse\Http\Requests\UniformApplyLocationBinRequest;
use Modules\Warehouse\Http\Resources\LocationBinResource;
use Modules\Warehouse\Services\LocationBinService;
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
        $paginator = $this->binService->getByLocationPaginated($locationId);

        $paginator->setCollection(
            LocationBinResource::collection($paginator->getCollection())->collection
        );

        return $this->successPaginatedResponse($paginator, 'Daftar bin berhasil diambil');
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
            $validated = $request->validated();
            $page = (int) ($validated['page'] ?? 1);
            $perPage = (int) ($validated['per_page'] ?? 50);

            $preview = $this->binService->previewMassGenerate($validated, $page, $perPage);

            return $this->successResponse(
                $preview['data'],
                'Preview berhasil di-generate.',
                200,
                $preview['meta']
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function uniformApply(UniformApplyLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $affected = $this->binService->uniformApply($locationId, $request->validated());

            return $this->successResponse(
                ['affected' => $affected],
                "Berhasil menerapkan ke {$affected} rak."
            );
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function store(StoreLocationBinRequest $request): JsonResponse
    {
        try {
            $bin = $this->binService->create($request->validated());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->errorResponse('Bin dengan kode tersebut sudah ada pada lokasi ini.', 422);
        }

        return $this->successResponse($bin, 'Bin berhasil dibuat', 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $bin = $this->binService->getById($id);
        if (! $bin) {
            return $this->errorResponse('Bin tidak ditemukan.', 404);
        }

        try {
            $this->binService->delete($id);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse(null, 'Bin berhasil dihapus');
    }

    public function generate(GenerateLocationBinRequest $request, string $locationId): JsonResponse
    {
        try {
            $result = $this->binService->massGenerate($locationId, $request->validated());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse($result, 'Bin berhasil di-generate', 201);
    }

    public function bulkUpdate(Request $request, string $locationId): JsonResponse
    {
        $validated = $request->validate([
            'bins' => 'required|array|min:1',
            'bins.*.id' => 'required|uuid',
            'bins.*.bin_final_code' => 'required|string|max:255',
            'bins.*.max_qty' => 'required|integer|min:0',
            'bins.*.is_stock_acknowledged' => 'required|boolean',
            'bins.*.is_large_bin' => 'required|boolean',
            'bins.*.category' => 'nullable|string|max:255',
        ]);

        try {
            $updated = $this->binService->bulkUpdate($locationId, $validated['bins']);
            return $this->successResponse(['updated' => $updated], 'Rak berhasil diperbarui.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->errorResponse('Kode rak harus unik dalam satu lokasi. Ada kode yang duplikat.', 422);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }
}
