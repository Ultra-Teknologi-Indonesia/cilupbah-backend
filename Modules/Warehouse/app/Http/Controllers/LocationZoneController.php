<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Services\LocationZoneService;

class LocationZoneController extends Controller
{
    public function __construct(
        protected LocationZoneService $zoneService
    ) {}

    public function index(string $locationId): JsonResponse
    {
        $zones = $this->zoneService->listByLocation($locationId);

        return $this->successResponse($zones, 'Daftar zona berhasil diambil');
    }

    public function store(Request $request, string $locationId): JsonResponse
    {
        $validated = $request->validate([
            'zone_code' => 'required|string|max:20',
            'zone_name' => 'nullable|string|max:100',
            'bin_ids' => 'nullable|array',
            'bin_ids.*' => 'uuid',
        ]);

        try {
            $zone = $this->zoneService->store($locationId, $validated);

            return $this->successResponse($zone, 'Zona berhasil dibuat', 201);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function update(Request $request, string $locationId, string $zoneId): JsonResponse
    {
        $validated = $request->validate([
            'zone_code' => 'sometimes|required|string|max:20',
            'zone_name' => 'nullable|string|max:100',
            'bin_ids' => 'nullable|array',
            'bin_ids.*' => 'uuid',
        ]);

        try {
            $zone = $this->zoneService->update($locationId, $zoneId, $validated);

            return $this->successResponse($zone, 'Zona berhasil diperbarui');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Zona tidak ditemukan.', 404);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }
    }

    public function destroy(string $locationId, string $zoneId): JsonResponse
    {
        try {
            $this->zoneService->destroy($locationId, $zoneId);

            return $this->successResponse(null, 'Zona berhasil dihapus');
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse('Zona tidak ditemukan.', 404);
        }
    }
}
