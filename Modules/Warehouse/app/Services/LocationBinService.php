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

    /**
     * Hapus bin. Ditolak bila: bin inbound (default), masih menyimpan stok aktif,
     * atau masih direferensikan transaksi (mis. inbound receipt — FK restrict).
     */
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

    /**
     * Generate massal bin untuk satu lokasi. Idempoten (firstOrCreate per bin_final_code),
     * dibungkus transaksi. Mengembalikan jumlah bin yang BARU dibuat.
     */
    public function massGenerate(string $locationId, array $data): array
    {
        if (! $this->locationRepository->exists($locationId)) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }

        $maxQty = $data['max_qty'] ?? 0;

        return DB::transaction(function () use ($locationId, $data, $maxQty) {
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
                                    'max_qty' => $maxQty,
                                    'is_inbound' => false,
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

    public function previewMassGenerate(array $data): array
    {
        $maxQty = $data['max_qty'] ?? 0;
        $previewData = [];

        for ($f = 1; $f <= $data['qty_floor']; $f++) {
            $fCode = "{$data['floor_code']}{$f}";
            for ($r = 1; $r <= $data['qty_row']; $r++) {
                $rCode = "{$data['row_code']}{$r}";
                for ($c = 1; $c <= $data['qty_column']; $c++) {
                    $cCode = "{$data['column_code']}{$c}";
                    for ($b = 1; $b <= $data['qty_bin']; $b++) {
                        $bCode = "{$data['bin_code']}{$b}";

                        $binData = [
                            'floor_code' => $fCode,
                            'row_code' => $rCode,
                            'column_code' => $cCode,
                            'bin_code' => $bCode,
                        ];

                        $previewData[] = array_merge($binData, [
                            'bin_final_code' => $this->generateFinalCode($binData),
                            'max_qty' => $maxQty,
                        ]);
                    }
                }
            }
        }

        return [
            'total_racks' => count($previewData),
            'preview_samples' => array_slice($previewData, 0, 10),
            'all_racks' => $previewData,
        ];
    }
}
