<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Warehouse\Services\LocationBinService;
use Modules\Warehouse\Http\Requests\StoreLocationBinRequest;

class LocationBinController extends Controller
{
    public function __construct(
        protected LocationBinService $binService
    ) {}

    public function index(int $locationId): JsonResponse
    {
        $bins = $this->binService->getByLocation($locationId);

        return response()->json([
            'status' => 'success',
            'data' => $bins,
        ]);
    }

    public function defaultBin(int $locationId): JsonResponse
    {
        $bin = $this->binService->getDefaultBin($locationId);

        if (!$bin) {
            return response()->json([
                'status' => 'error',
                'message' => 'Default bin tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $bin,
        ]);
    }

    public function store(StoreLocationBinRequest $request): JsonResponse
    {
        try {
            $bin = $this->binService->create($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Bin berhasil dibuat.',
                'data' => $bin,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->binService->delete($id);

            if (!$deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Bin tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Bin berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
