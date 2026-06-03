<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\LocationService;
use Modules\Warehouse\Http\Requests\StoreLocationRequest;
use Modules\Warehouse\Http\Requests\UpdateLocationRequest;

class LocationController extends Controller
{
    public function __construct(
        protected LocationService $locationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $locations = $this->locationService->getAll($request->only([
            'is_active', 'location_type', 'search'
        ]));

        return response()->json([
            'status' => 'success',
            'data' => $locations,
        ]);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        try {
            $location = $this->locationService->create($request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Lokasi berhasil dibuat.',
                'data' => $location,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $location = $this->locationService->getById($id);

        if (!$location) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lokasi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $location,
        ]);
    }

    public function update(UpdateLocationRequest $request, int $id): JsonResponse
    {
        $updated = $this->locationService->update($id, $request->validated());

        if (!$updated) {
            return response()->json([
                'status' => 'error',
                'message' => 'Lokasi tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Lokasi berhasil diperbarui.',
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $deleted = $this->locationService->delete($id);

            if (!$deleted) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Lokasi tidak ditemukan.',
                ], 404);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Lokasi berhasil dihapus.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
