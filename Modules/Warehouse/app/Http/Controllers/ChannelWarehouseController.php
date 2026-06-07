<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\ChannelWarehouseService;
use Modules\Warehouse\Http\Requests\StoreChannelWarehouseRequest;
use Modules\Warehouse\Models\ChannelWarehouse;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Channel Warehouses', description: 'API Endpoints for Channel Warehouse Mapping')]
#[OA\Schema(
    schema: 'ChannelWarehouse',
    title: 'Channel Warehouse Schema',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'channel_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'store_id', type: 'string', example: 'STORE-123'),
        new OA\Property(property: 'channel_location_id', type: 'string', example: 'CH-LOC-123'),
        new OA\Property(property: 'channel_location_type', type: 'string', example: 'WAREHOUSE', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
    ]
)]
#[OA\Schema(
    schema: 'StoreChannelWarehouseRequest',
    required: ['location_id', 'channel_id', 'store_id', 'channel_location_id'],
    type: 'object',
    properties: [
        new OA\Property(property: 'location_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'channel_id', type: 'string', example: '019ea2afad1d733eafb905816d10590e'),
        new OA\Property(property: 'store_id', type: 'string', example: 'STORE-123'),
        new OA\Property(property: 'channel_location_id', type: 'string', example: 'CH-LOC-123'),
        new OA\Property(property: 'channel_location_type', type: 'string', example: 'WAREHOUSE', nullable: true),
    ]
)]
class ChannelWarehouseController extends Controller
{
    public function __construct(
        protected ChannelWarehouseService $channelWarehouseService
    ) {}

    #[OA\Get(
        path: '/api/v1/channel-warehouses',
        summary: 'Get list of channel warehouse mappings',
        security: [['bearerAuth' => []]],
        tags: ['Channel Warehouses'],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Number of items per page', schema: new OA\Schema(type: 'integer', default: 10))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful operation',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ChannelWarehouse')),
                        new OA\Property(property: 'message', type: 'string', example: 'Daftar mapping warehouse berhasil diambil')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $mappings = $this->channelWarehouseService->getAllPaginated($limit);

        return $this->successPaginatedResponse($mappings, 'Daftar mapping warehouse berhasil diambil');
    }

    #[OA\Post(
        path: '/api/v1/channel-warehouses',
        summary: 'Create a new channel warehouse mapping',
        security: [['bearerAuth' => []]],
        tags: ['Channel Warehouses'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreChannelWarehouseRequest')
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Mapping created successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', ref: '#/components/schemas/ChannelWarehouse'),
                        new OA\Property(property: 'message', type: 'string', example: 'Channel warehouse mapping berhasil dibuat.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation Error')
        ]
    )]
    public function store(StoreChannelWarehouseRequest $request): JsonResponse
    {
        try {
            $mapping = $this->channelWarehouseService->create($request->validated());

            return $this->successResponse($mapping, 'Channel warehouse mapping berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    #[OA\Delete(
        path: '/api/v1/channel-warehouses/{id}',
        summary: 'Delete a channel warehouse mapping',
        security: [['bearerAuth' => []]],
        tags: ['Channel Warehouses'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID of the mapping to delete', schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mapping deleted successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'data', type: 'object', nullable: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Mapping berhasil dihapus.')
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 404, description: 'Mapping tidak ditemukan.')
        ]
    )]
    public function destroy(string $id): JsonResponse
    {
        $deleted = $this->channelWarehouseService->delete($id);

        if (!$deleted) {
            return $this->errorResponse('Mapping tidak ditemukan.', 404);
        }

        return $this->successResponse(null, 'Mapping berhasil dihapus.');
    }
}
