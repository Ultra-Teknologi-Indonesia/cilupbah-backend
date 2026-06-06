<?php

namespace Modules\Warehouse\Services;

use Modules\Warehouse\Repositories\LocationBinRepository;
use Modules\Warehouse\Models\LocationBin;
use Illuminate\Database\Eloquent\Collection;

class LocationBinService
{
    public function __construct(
        protected LocationBinRepository $binRepository
    ) {}

    public function getByLocation(int $locationId): Collection
    {
        return $this->binRepository->findByLocation($locationId);
    }

    public function getById(int $id): ?LocationBin
    {
        return $this->binRepository->findById($id);
    }

    public function getDefaultBin(int $locationId): ?LocationBin
    {
        return $this->binRepository->getDefaultBin($locationId);
    }

    public function create(array $data): LocationBin
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $data['bin_final_code'] = $this->generateFinalCode($data);
            return $this->binRepository->create($data);
        });
    }

    public function update(int $id, array $data): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id, $data) {
            if (isset($data['floor_code']) || isset($data['row_code']) || isset($data['column_code']) || isset($data['bin_code'])) {
                $bin = $this->binRepository->findById($id);
                $merged = array_merge($bin->toArray(), $data);
                $data['bin_final_code'] = $this->generateFinalCode($merged);
            }

            return $this->binRepository->update($id, $data);
        });
    }

    public function delete(int $id): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($id) {
            $bin = $this->binRepository->findById($id);
            if (!$bin) {
                return false;
            }

            if ($bin->is_inbound) {
                throw new \Exception('Bin inbound (default) tidak dapat dihapus.');
            }

            return $this->binRepository->delete($id);
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

        return !empty($parts) ? implode('-', $parts) : 'DEFAULT';
    }

    public function massGenerate(int $locationId, array $data): int
    {
        $floorCode = $data['floor_code'];
        $qtyFloor = $data['qty_floor'];
        $rowCode = $data['row_code'];
        $qtyRow = $data['qty_row'];
        $columnCode = $data['column_code'];
        $qtyColumn = $data['qty_column'];
        $binCode = $data['bin_code'];
        $qtyBin = $data['qty_bin'];
        $maxQty = $data['max_qty'] ?? 0;

        $now = now();
        $insertData = [];

        for ($f = 1; $f <= $qtyFloor; $f++) {
            $fCode = "{$floorCode}{$f}";
            for ($r = 1; $r <= $qtyRow; $r++) {
                $rCode = "{$rowCode}{$r}";
                for ($c = 1; $c <= $qtyColumn; $c++) {
                    $cCode = "{$columnCode}{$c}";
                    for ($b = 1; $b <= $qtyBin; $b++) {
                        $bCode = "{$binCode}{$b}";

                        $binData = [
                            'floor_code' => $fCode,
                            'row_code' => $rCode,
                            'column_code' => $cCode,
                            'bin_code' => $bCode,
                        ];

                        $finalCode = $this->generateFinalCode($binData);

                        $insertData[] = [
                            'location_id' => $locationId,
                            'floor_code' => $fCode,
                            'row_code' => $rCode,
                            'column_code' => $cCode,
                            'bin_code' => $bCode,
                            'bin_final_code' => $finalCode,
                            'is_inbound' => false,
                            'max_qty' => $maxQty,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        if (!empty($insertData)) {
            LocationBin::insert($insertData);
        }

        return count($insertData);
    }
}
