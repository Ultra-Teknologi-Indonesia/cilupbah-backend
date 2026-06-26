<?php

namespace Modules\Warehouse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Warehouse\Repositories\LocationZoneRepository;
use Modules\Warehouse\Models\LocationBin;

class LocationZoneController extends Controller
{
    public function __construct(
        protected LocationZoneRepository $zoneRepository
    ) {}

    public function index(string $locationId): JsonResponse
    {
        $zones = $this->zoneRepository->findByLocation($locationId);

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

        $existing = $this->zoneRepository->findByLocationAndCode($locationId, $validated['zone_code']);
        if ($existing) {
            return $this->errorResponse('Kode zona sudah digunakan di lokasi ini.', 422);
        }

        $zone = $this->zoneRepository->create([
            'location_id' => $locationId,
            'zone_code' => $validated['zone_code'],
            'zone_name' => $validated['zone_name'] ?? null,
        ]);

        if (! empty($validated['bin_ids'])) {
            LocationBin::where('location_id', $locationId)
                ->whereIn('id', $validated['bin_ids'])
                ->whereNull('zone_id')
                ->update(['zone_id' => $zone->id]);
        }

        $zone->loadCount('bins');

        return $this->successResponse($zone, 'Zona berhasil dibuat', 201);
    }

    public function update(Request $request, string $locationId, string $zoneId): JsonResponse
    {
        $zone = $this->zoneRepository->findById($zoneId);

        if (! $zone || $zone->location_id !== $locationId) {
            return $this->errorResponse('Zona tidak ditemukan.', 404);
        }

        $validated = $request->validate([
            'zone_code' => 'sometimes|required|string|max:20',
            'zone_name' => 'nullable|string|max:100',
            'bin_ids' => 'nullable|array',
            'bin_ids.*' => 'uuid',
        ]);

        if (isset($validated['zone_code']) && $validated['zone_code'] !== $zone->zone_code) {
            $existing = $this->zoneRepository->findByLocationAndCode($locationId, $validated['zone_code']);
            if ($existing) {
                return $this->errorResponse('Kode zona sudah digunakan di lokasi ini.', 422);
            }
        }

        $zone->update([
            'zone_code' => $validated['zone_code'] ?? $zone->zone_code,
            'zone_name' => array_key_exists('zone_name', $validated) ? $validated['zone_name'] : $zone->zone_name,
        ]);

        if (array_key_exists('bin_ids', $validated)) {
            LocationBin::where('location_id', $locationId)
                ->where('zone_id', $zone->id)
                ->update(['zone_id' => null]);

            if (! empty($validated['bin_ids'])) {
                LocationBin::where('location_id', $locationId)
                    ->whereIn('id', $validated['bin_ids'])
                    ->where(function ($q) use ($zone) {
                        $q->whereNull('zone_id')->orWhere('zone_id', $zone->id);
                    })
                    ->update(['zone_id' => $zone->id]);
            }
        }

        $zone->loadCount('bins');

        return $this->successResponse($zone, 'Zona berhasil diperbarui');
    }

    public function destroy(string $locationId, string $zoneId): JsonResponse
    {
        $zone = $this->zoneRepository->findById($zoneId);

        if (! $zone || $zone->location_id !== $locationId) {
            return $this->errorResponse('Zona tidak ditemukan.', 404);
        }

        LocationBin::where('zone_id', $zone->id)->update(['zone_id' => null]);

        $zone->delete();

        return $this->successResponse(null, 'Zona berhasil dihapus');
    }
}
