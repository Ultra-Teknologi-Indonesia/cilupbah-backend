<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Models\LocationZone;
use Modules\Warehouse\Repositories\LocationZoneRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LocationZoneService
{
    public function __construct(
        protected LocationZoneRepository $zoneRepository
    ) {}

    public function listByLocation(string $locationId): Collection
    {
        return $this->zoneRepository->findByLocation($locationId);
    }

    public function store(string $locationId, array $data): LocationZone
    {
        $existing = $this->zoneRepository->findByLocationAndCode($locationId, $data['zone_code']);
        if ($existing) {
            throw new \DomainException('Kode zona sudah digunakan di lokasi ini.');
        }

        return DB::transaction(function () use ($locationId, $data) {
            $zone = $this->zoneRepository->create([
                'location_id' => $locationId,
                'zone_code' => $data['zone_code'],
                'zone_name' => $data['zone_name'] ?? null,
            ]);

            if (! empty($data['bin_ids'])) {
                $this->zoneRepository->assignBinsToZone($locationId, $data['bin_ids'], $zone->id);
            }

            $zone->loadCount('bins');

            return $zone;
        });
    }

    public function update(string $locationId, string $zoneId, array $data): LocationZone
    {
        $zone = $this->zoneRepository->findById($zoneId);

        if (! $zone || $zone->location_id !== $locationId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Zona tidak ditemukan.');
        }

        if (isset($data['zone_code']) && $data['zone_code'] !== $zone->zone_code) {
            $existing = $this->zoneRepository->findByLocationAndCode($locationId, $data['zone_code']);
            if ($existing) {
                throw new \DomainException('Kode zona sudah digunakan di lokasi ini.');
            }
        }

        return DB::transaction(function () use ($locationId, $zone, $data) {
            $this->zoneRepository->update($zone, [
                'zone_code' => $data['zone_code'] ?? $zone->zone_code,
                'zone_name' => array_key_exists('zone_name', $data) ? $data['zone_name'] : $zone->zone_name,
            ]);

            if (array_key_exists('bin_ids', $data)) {
                $this->zoneRepository->clearBinsFromZone($locationId, $zone->id);

                if (! empty($data['bin_ids'])) {
                    $this->zoneRepository->reassignBinsToZone($locationId, $data['bin_ids'], $zone->id);
                }
            }

            $zone->loadCount('bins');

            return $zone;
        });
    }

    public function destroy(string $locationId, string $zoneId): void
    {
        $zone = $this->zoneRepository->findById($zoneId);

        if (! $zone || $zone->location_id !== $locationId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Zona tidak ditemukan.');
        }

        DB::transaction(function () use ($locationId, $zone) {
            $this->zoneRepository->clearBinsFromZone($locationId, $zone->id);
            $this->zoneRepository->delete($zone);
        });
    }
}
