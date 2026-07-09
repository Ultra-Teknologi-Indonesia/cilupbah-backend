<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Repositories\LocationRepository;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class LocationBinService
{
    public function __construct(
        protected LocationBinRepository $binRepository,
        protected LocationRepository $locationRepository
    ) {}

    public function getByLocation(string $locationId): Collection
    {
        return $this->binRepository->findByLocation($locationId);
    }

    public function getByLocationPaginated(string $locationId)
    {
        return $this->binRepository->findByLocationPaginated($locationId);
    }

    public function getById(string $id): ?LocationBin
    {
        return $this->binRepository->findById($id);
    }

    public function getDefaultBin(string $locationId): ?LocationBin
    {
        return $this->binRepository->getDefaultBin($locationId);
    }

    public function create(array $data): LocationBin
    {
        $data['bin_final_code'] = $this->generateFinalCode($data);

        return $this->binRepository->create($data);
    }

    public function update(string $id, array $data): bool
    {
        $bin = $this->binRepository->findById($id);
        if (! $bin) {
            return false;
        }

        $merged = array_merge(
            $bin->only(['floor_code', 'row_code', 'column_code', 'bin_code']),
            $data
        );
        $data['bin_final_code'] = $this->generateFinalCode($merged);

        return $this->binRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        $bin = $this->binRepository->findById($id);
        if (! $bin) {
            return false;
        }

        if ($bin->is_inbound) {
            throw new \DomainException('Bin inbound (default) tidak dapat dihapus.');
        }

        if ($this->binRepository->hasActiveStock($id)) {
            throw new \DomainException('Bin tidak dapat dihapus karena masih menyimpan stok.');
        }

        try {
            return $this->binRepository->delete($id);
        } catch (QueryException $e) {
            throw new \DomainException('Bin tidak dapat dihapus karena masih dipakai oleh transaksi lain.');
        }
    }

    public function massGenerate(string $locationId, array $data): array
    {
        if (! $this->locationRepository->exists($locationId)) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }

        return DB::transaction(function () use ($locationId, $data) {
            $created = 0;

            for ($f = 1; $f <= $data['qty_floor']; $f++) {
                for ($r = 1; $r <= $data['qty_row']; $r++) {
                    for ($c = 1; $c <= $data['qty_column']; $c++) {
                        for ($b = 1; $b <= $data['qty_bin']; $b++) {
                            $codes = [
                                'floor_code' => "{$data['floor_code']}{$f}",
                                'row_code' => "{$data['row_code']}{$r}",
                                'column_code' => "{$data['column_code']}{$c}",
                                'bin_code' => "{$data['bin_code']}{$b}",
                            ];

                            $finalCode = $this->generateFinalCode($codes);

                            [, $isNew] = $this->binRepository->firstOrCreateByFinalCode(
                                $locationId,
                                $finalCode,
                                array_merge($codes, [
                                    'is_inbound' => false,
                                    'is_stock_acknowledged' => true,
                                    'is_large_bin' => false,
                                    'category' => null,
                                ])
                            );

                            if ($isNew) {
                                $created++;
                            }
                        }
                    }
                }
            }

            return ['generated_count' => $created];
        });
    }

    protected function generateFinalCode(array $data): string
    {
        $parts = array_filter([
            $data['floor_code'] ?? null,
            $data['row_code'] ?? null,
            $data['column_code'] ?? null,
            $data['bin_code'] ?? null,
        ]);

        return ! empty($parts) ? implode('-', $parts) : 'DEFAULT';
    }

    public function bulkUpdate(string $locationId, array $bins): int
    {
        return DB::transaction(function () use ($locationId, $bins) {
            $updated = 0;
            foreach ($bins as $binData) {
                $payload = [
                    'is_stock_acknowledged' => $binData['is_stock_acknowledged'],
                    'is_large_bin' => $binData['is_large_bin'],
                    'category' => $binData['category'] ?? null,
                ];

                if (array_key_exists('bin_final_code', $binData)) {
                    $payload['bin_final_code'] = $binData['bin_final_code'];
                }

                $affected = LocationBin::where('location_id', $locationId)
                    ->where('id', $binData['id'])
                    ->update($payload);
                $updated += $affected;
            }
            return $updated;
        });
    }

    public function previewMassGenerate(array $data, int $page = 1, int $perPage = 50): array
    {
        $qtyFloor = (int) $data['qty_floor'];
        $qtyRow = (int) $data['qty_row'];
        $qtyColumn = (int) $data['qty_column'];
        $qtyBin = (int) $data['qty_bin'];

        $total = $qtyFloor * $qtyRow * $qtyColumn * $qtyBin;
        $perPage = max(1, min($perPage, 1000));
        $page = max(1, $page);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $items = [];
        if ($offset < $total) {
            $end = min($offset + $perPage, $total);
            for ($i = $offset; $i < $end; $i++) {
                $items[] = $this->buildPreviewRowAtIndex($i, $data);
            }
        }

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    protected function buildPreviewRowAtIndex(int $index, array $data): array
    {
        $qtyRow = (int) $data['qty_row'];
        $qtyColumn = (int) $data['qty_column'];
        $qtyBin = (int) $data['qty_bin'];

        $perFloor = $qtyRow * $qtyColumn * $qtyBin;
        $perRow = $qtyColumn * $qtyBin;
        $perColumn = $qtyBin;

        $f = intdiv($index, $perFloor) + 1;
        $rem = $index % $perFloor;
        $r = intdiv($rem, $perRow) + 1;
        $rem = $rem % $perRow;
        $c = intdiv($rem, $perColumn) + 1;
        $b = ($rem % $perColumn) + 1;

        $codes = [
            'floor_code' => "{$data['floor_code']}{$f}",
            'row_code' => "{$data['row_code']}{$r}",
            'column_code' => "{$data['column_code']}{$c}",
            'bin_code' => "{$data['bin_code']}{$b}",
        ];

        return array_merge($codes, [
            'bin_final_code' => $this->generateFinalCode($codes),
        ]);
    }

    public function uniformApply(string $locationId, array $payload): int
    {
        $scope = $payload['scope'];
        $values = $payload['values'];

        $updateData = array_filter([
            'is_stock_acknowledged' => array_key_exists('is_stock_acknowledged', $values) ? (bool) $values['is_stock_acknowledged'] : null,
            'is_large_bin' => array_key_exists('is_large_bin', $values) ? (bool) $values['is_large_bin'] : null,
            'category' => array_key_exists('category', $values) ? $values['category'] : null,
            'zone_id' => array_key_exists('zone_id', $values) ? $values['zone_id'] : null,
        ], fn($v) => $v !== null);

        if (empty($updateData)) {
            return 0;
        }

        if ($scope === 'selected') {
            return $this->binRepository->updateManyByIds(
                $locationId,
                $payload['ids'] ?? [],
                $updateData
            );
        }

        $query = $this->binRepository->applyFilterQuery($locationId);

        return $query->update($updateData);
    }
}
