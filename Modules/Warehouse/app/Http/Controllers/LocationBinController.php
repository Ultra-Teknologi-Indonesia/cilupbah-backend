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

        return $this->successResponse($bins, 'Daftar bin berhasil diambil');
    }

    public function defaultBin(int $locationId): JsonResponse
    {
        $bin = $this->binService->getDefaultBin($locationId);

        if (!$bin) {
            return $this->errorResponse('Default bin tidak ditemukan.', 404);
        }

        return $this->successResponse($bin, 'Default bin berhasil diambil');
    }

    public function store(StoreLocationBinRequest $request): JsonResponse
    {
        try {
            $bin = $this->binService->create($request->validated());

            return $this->successResponse($bin, 'Bin berhasil dibuat.', 201);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

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
