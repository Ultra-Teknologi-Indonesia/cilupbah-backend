<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\LocationService;
use Modules\Warehouse\Http\Requests\StoreLocationRequest;
use Modules\Warehouse\Http\Requests\UpdateLocationRequest;
use Modules\Warehouse\Models\Location;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class LocationController extends Controller
{
    public function __construct(
        protected LocationService $locationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $limit = $request->query('limit', 10);
        $locations = $this->locationService->getAllPaginated($limit);

        return $this->successPaginatedResponse($locations, 'Daftar lokasi berhasil diambil');
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        try {
            $location = $this->locationService->create($request->validated());

            return $this->successResponse($location, 'Lokasi berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $location = $this->locationService->getById($id);

        if (!$location) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse($location, 'Detail lokasi berhasil diambil');
    }

    public function update(UpdateLocationRequest $request, int $id): JsonResponse
    {
        $updated = $this->locationService->update($id, $request->validated());

        if (!$updated) {
            return $this->errorResponse('Lokasi tidak ditemukan.', 404);
        }

        return $this->successResponse(null, 'Lokasi berhasil diperbarui.');
    }

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
