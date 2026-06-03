<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\ChannelWarehouseService;
use Modules\Warehouse\Http\Requests\StoreChannelWarehouseRequest;

class ChannelWarehouseController extends Controller
{
    public function __construct(
        protected ChannelWarehouseService $channelWarehouseService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $mappings = $this->channelWarehouseService->getByLocation(
            (int) $request->query('location_id')
        );

        return response()->json([
            'status' => 'success',
            'data' => $mappings,
        ]);
    }

    public function store(StoreChannelWarehouseRequest $request): JsonResponse
    {
        try {
            $mapping = $this->channelWarehouseService->create($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Channel warehouse mapping berhasil dibuat.',
                'data' => $mapping,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->channelWarehouseService->delete($id);

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Mapping tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Mapping berhasil dihapus.',
        ]);
    }
}
