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

    public function getByLocation(string $locationId): Collection
    {
        return $this->binRepository->findByLocation($locationId);
    }

    public function getById(string $id): ?LocationBin
    {
        return $this->binRepository->findById($id);
    }

    public function getDefaultBin(string $locationId): ?LocationBin
    {
        return $this->binRepository->getDefaultBin($locationId);
    }

    /** Buat satu bin; bin_final_code dihitung dari kode floor/row/column/bin. */
    public function create(array $data): LocationBin
    {
        $data['bin_final_code'] = $this->generateFinalCode($data);

        return $this->binRepository->create($data);
    }

    /** Update bin; bin_final_code dihitung ulang dari hasil merge. */
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

    /** Hapus bin; bin inbound (default) tidak boleh dihapus. */
    public function delete(string $id): bool
    {
        $bin = $this->binRepository->findById($id);
        if (! $bin) {
            return false;
        }

        if ($bin->is_inbound) {
            throw new \DomainException('Bin inbound (default) tidak dapat dihapus.');
        }

        return $this->binRepository->delete($id);
    }

    /** Generate massal bin untuk satu lokasi. Mengembalikan jumlah yang dibuat. */
    public function massGenerate(string $locationId, array $data): array
    {
        $maxQty = $data['max_qty'] ?? 0;
        $generated = 0;

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

                        $this->binRepository->create(array_merge($codes, [
                            'location_id' => $locationId,
                            'bin_final_code' => $this->generateFinalCode($codes),
                            'max_qty' => $maxQty,
                            'is_inbound' => false,
                        ]));

                        $generated++;
                    }
                }
            }
        }

        return ['generated_count' => $generated];
    }

    // Protected methods
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

    public function previewMassGenerate(array $data): array
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

        $previewData = [];

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

                        $previewData[] = [
                            'floor_code' => $fCode,
                            'row_code' => $rCode,
                            'column_code' => $cCode,
                            'bin_code' => $bCode,
                            'bin_final_code' => $finalCode,
                            'max_qty' => $maxQty,
                        ];
                    }
                }
            }
        }

        return [
            'total_racks' => count($previewData),
            'preview_samples' => array_slice($previewData, 0, 10), // return top 10 as sample for UI
            'all_racks' => $previewData
        ];
    }
}
