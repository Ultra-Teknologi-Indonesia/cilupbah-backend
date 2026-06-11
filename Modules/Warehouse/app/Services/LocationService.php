<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationRepository;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationZone;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LocationService
{
    public function __construct(
        protected LocationRepository $locationRepository,
        protected LocationBinRepository $binRepository
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->locationRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Location
    {
        if (! $this->isValidUuid($id)) {
            return null;
        }

        return $this->locationRepository->findById($id);
    }

    /** Guard format UUID agar id non-UUID tidak memicu error cast Postgres (return null/false -> 404). */
    protected function isValidUuid(string $id): bool
    {
        $normalized = str_replace('-', '', $id);

        return strlen($normalized) === 32 && ctype_xdigit($normalized);
    }

    public function create(array $data): Location
    {
        return DB::transaction(function () use ($data) {
            $location = $this->locationRepository->create($data);

            $this->binRepository->create([
                'location_id' => $location->id,
                'bin_final_code' => 'DEFAULT',
                'max_qty' => 0,
                'is_inbound' => true,
            ]);

            if (!empty($data['layout']) && is_array($data['layout'])) {
                $this->syncLayout($location->id, $data['layout']);
            }

            return $location->load(['bins', 'zones.bins', 'village.district.city.province']);
        });
    }

    public function update(string $id, array $data): bool
    {
        if (! $this->isValidUuid($id)) {
            return false;
        }

        return DB::transaction(function () use ($id, $data) {
            $updated = $this->locationRepository->update($id, $data);

            if (isset($data['layout']) && is_array($data['layout'])) {
                $this->syncLayout($id, $data['layout']);
            }

            return $updated;
        });
    }

    protected function syncLayout(string $locationId, array $layout): void
    {
        $incomingZoneCodes = array_column($layout, 'zone_code');

        // Delete zones that are not in the layout
        $zonesToDelete = LocationZone::where('location_id', $locationId)
            ->whereNotIn('zone_code', $incomingZoneCodes)
            ->get();
            
        foreach ($zonesToDelete as $zone) {
            $zone->delete(); // this will cascade delete bins if DB cascade is set, or we can manually delete
            LocationBin::where('zone_id', $zone->id)->delete();
        }

        $now = now();

        foreach ($layout as $zoneData) {
            $zoneCode = $zoneData['zone_code'];
            
            // Check if zone exists
            $existingZone = LocationZone::where('location_id', $locationId)
                ->where('zone_code', $zoneCode)
                ->first();

            if (!$existingZone) {
                // Create new zone
                $newZone = LocationZone::create([
                    'location_id' => $locationId,
                    'zone_code' => $zoneCode,
                    'zone_name' => $zoneData['zone_name'] ?? null,
                ]);

                // Insert racks
                if (!empty($zoneData['racks']) && is_array($zoneData['racks'])) {
                    $insertData = [];
                    foreach ($zoneData['racks'] as $rack) {
                        $insertData[] = [
                            'location_id' => $locationId,
                            'zone_id' => $newZone->id,
                            'floor_code' => $rack['floor_code'],
                            'row_code' => $rack['row_code'],
                            'column_code' => $rack['column_code'],
                            'bin_code' => $rack['bin_code'],
                            'bin_final_code' => $rack['bin_final_code'],
                            'max_qty' => $rack['max_qty'] ?? 0,
                            'is_inbound' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    if (!empty($insertData)) {
                        LocationBin::insert($insertData);
                    }
                }
            } else {
                // If zone exists, update its name if changed
                if (isset($zoneData['zone_name']) && $existingZone->zone_name !== $zoneData['zone_name']) {
                    $existingZone->update(['zone_name' => $zoneData['zone_name']]);
                }
                
                // Note: Racks inside existing zones are strictly untouched per business rules.
            }
        }
    }

    public function delete(string $id): bool
    {
        if (! $this->isValidUuid($id)) {
            return false;
        }

        return DB::transaction(function () use ($id) {
            $location = $this->locationRepository->findById($id);
            if (!$location) {
                return false;
            }

            $hasInventory = \Modules\Inventory\Models\Inventory::where('location_id', $id)->exists();

            if ($hasInventory) {
                throw new \Exception('Lokasi tidak dapat dihapus karena masih memiliki data stok.');
            }

            return $this->locationRepository->delete($id);
        });
    }

    public function getActiveWarehouses(): Collection
    {
        return $this->locationRepository->getActiveWarehouses();
    }

    public function getFulfillmentLocations(): Collection
    {
        return $this->locationRepository->getFulfillmentLocations();
    }

    /** Daftar lokasi outlet POS (paginated). */
    public function getPosLocations()
    {
        return $this->locationRepository->getPosPaginated();
    }
}
