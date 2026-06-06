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
