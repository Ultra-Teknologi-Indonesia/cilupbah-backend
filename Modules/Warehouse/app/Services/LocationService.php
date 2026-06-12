<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationRepository;
use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Repositories\LocationZoneRepository;
use Modules\Warehouse\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LocationService
{
    public function __construct(
        protected LocationRepository $locationRepository,
        protected LocationBinRepository $binRepository,
        protected LocationZoneRepository $zoneRepository
    ) {}

    public function getAllPaginated()
    {
        return $this->locationRepository->getAllPaginated();
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
        $layout = $data['layout'] ?? null;
        unset($data['layout']);

        return DB::transaction(function () use ($data, $layout) {
            $location = $this->locationRepository->create($data);

            $this->binRepository->create([
                'location_id' => $location->id,
                'bin_final_code' => 'DEFAULT',
                'max_qty' => 0,
                'is_inbound' => true,
            ]);

            if (! empty($layout) && is_array($layout)) {
                $this->syncLayout($location->id, $layout);
            }

            return $location->load(['bins', 'zones.bins', 'village.district.city.province']);
        });
    }

    public function update(string $id, array $data): ?Location
    {
        if (! $this->isValidUuid($id)) {
            return null;
        }

        // Key `layout` bukan kolom tabel locations — wajib dipisah sebelum update repository,
        // jika ikut terkirim akan memicu "column layout does not exist" (500).
        $layout = $data['layout'] ?? null;
        unset($data['layout']);

        return DB::transaction(function () use ($id, $data, $layout) {
            $location = $this->locationRepository->findById($id);
            if (! $location) {
                return null;
            }

            if (! empty($data)) {
                $this->locationRepository->update($id, $data);
            }

            if (is_array($layout)) {
                $this->syncLayout($id, $layout);
            }

            return $this->locationRepository->findById($id);
        });
    }

    /**
     * Sinkronkan layout zona+rak. Zona yang tidak ada di payload dihapus (cascade ke bin),
     * tetapi DITOLAK bila zona masih menyimpan stok aktif agar stok tidak ter-detach diam-diam.
     * Rak di dalam zona yang sudah ada tidak diubah (aturan bisnis).
     */
    protected function syncLayout(string $locationId, array $layout): void
    {
        $incomingZoneCodes = array_values(array_filter(array_column($layout, 'zone_code')));

        $zonesToDelete = $this->zoneRepository->getByLocationExcludingCodes($locationId, $incomingZoneCodes);

        foreach ($zonesToDelete as $zone) {
            if ($this->binRepository->zoneHasActiveStock($zone->id)) {
                throw new \DomainException("Zona {$zone->zone_code} tidak dapat dihapus karena masih memiliki stok.");
            }

            $this->binRepository->deleteByZone($zone->id);
            $this->zoneRepository->delete($zone->id);
        }

        foreach ($layout as $zoneData) {
            $zoneCode = $zoneData['zone_code'];
            $existingZone = $this->zoneRepository->findByLocationAndCode($locationId, $zoneCode);

            if (! $existingZone) {
                $newZone = $this->zoneRepository->create([
                    'location_id' => $locationId,
                    'zone_code' => $zoneCode,
                    'zone_name' => $zoneData['zone_name'] ?? null,
                ]);

                $rows = [];
                foreach ($zoneData['racks'] ?? [] as $rack) {
                    $rows[] = [
                        'location_id' => $locationId,
                        'zone_id' => $newZone->id,
                        'floor_code' => $rack['floor_code'],
                        'row_code' => $rack['row_code'],
                        'column_code' => $rack['column_code'],
                        'bin_code' => $rack['bin_code'],
                        'bin_final_code' => $rack['bin_final_code'],
                        'max_qty' => $rack['max_qty'] ?? 0,
                        'is_inbound' => false,
                    ];
                }

                $this->binRepository->insertMany($rows);
            } else {
                // Zona sudah ada: hanya perbarui nama. Rak di dalamnya tidak disentuh (aturan bisnis).
                $this->zoneRepository->updateName($existingZone, $zoneData['zone_name'] ?? null);
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
            if (! $location) {
                return false;
            }

            $hasInventory = \Modules\Inventory\Models\Inventory::where('location_id', $id)->exists();

            if ($hasInventory) {
                throw new \DomainException('Lokasi tidak dapat dihapus karena masih memiliki data stok.');
            }

            try {
                return $this->locationRepository->delete($id);
            } catch (QueryException $e) {
                // FK restrict lain (inbound, purchase order, sales return, transfer) -> tolak dengan pesan ramah.
                throw new \DomainException('Lokasi tidak dapat dihapus karena masih dipakai oleh transaksi lain (inbound/pembelian/retur/transfer).');
            }
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
