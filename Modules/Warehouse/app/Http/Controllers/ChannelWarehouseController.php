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

class ChannelWarehouseController extends Controller
{
    public function __construct(
        protected ChannelWarehouseService $channelWarehouseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $mappings = $this->channelWarehouseService->getAllPaginated($limit);

        return $this->successPaginatedResponse($mappings, 'Daftar mapping warehouse berhasil diambil');
    }

    public function store(StoreChannelWarehouseRequest $request): JsonResponse
    {
        try {
            $mapping = $this->channelWarehouseService->create($request->validated());

            return $this->successResponse($mapping, 'Channel warehouse mapping berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->channelWarehouseService->delete($id);

        if (!$deleted) {
            return $this->errorResponse('Mapping tidak ditemukan.', 404);
        }

        return $this->successResponse(null, 'Mapping berhasil dihapus.');
    }
}
