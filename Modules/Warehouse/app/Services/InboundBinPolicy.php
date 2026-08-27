<?php

namespace Modules\Warehouse\Services;

use App\Exceptions\UserFacingException;
use Modules\Warehouse\Models\LocationBin;

class InboundBinPolicy
{
    public function assertInbound(string $locationId, ?string $binId, string $operation): LocationBin
    {
        $bin = $this->binAtLocation($locationId, $binId, $operation);

        if (! $bin->is_inbound) {
            throw new UserFacingException(
                'Rak Inbound Tidak Valid',
                "Rak {$bin->bin_final_code} bukan bin inbound/DEFAULT. {$operation} hanya boleh menambah atau mengurangi stok pada bin inbound.",
                422,
                ['bin_id' => $bin->id, 'operation' => $operation],
            );
        }

        return $bin;
    }

    public function assertConsumable(string $locationId, ?string $binId, string $operation): LocationBin
    {
        $bin = $this->binAtLocation($locationId, $binId, $operation);

        if ($bin->is_inbound) {
            throw new UserFacingException(
                'Stok Belum Ditempatkan',
                "Rak {$bin->bin_final_code} adalah bin inbound/DEFAULT. Stok penerimaan wajib ditempatkan (putaway) terlebih dahulu sebelum {$operation}.",
                422,
            ['bin_id' => $bin->id, 'operation' => $operation],
        );
        }

        return $bin;
    }

    public function assertPutawayRoute(string $locationId, ?string $sourceBinId, ?string $destinationBinId): void
    {
        $source = $this->binAtLocation($locationId, $sourceBinId, 'penempatan barang');
        $destination = $this->binAtLocation($locationId, $destinationBinId, 'penempatan barang');

        if (! $source->is_inbound) {
            throw new UserFacingException(
                'Sumber Penempatan Tidak Valid',
                "Rak asal {$source->bin_final_code} bukan bin inbound. Penempatan hanya boleh mengambil stok penerimaan dari bin inbound/DEFAULT.",
                422,
            );
        }

        if ($destination->is_inbound) {
            throw new UserFacingException(
                'Tujuan Penempatan Tidak Valid',
                "Rak tujuan {$destination->bin_final_code} adalah bin inbound/DEFAULT. Pilih rak penyimpanan final untuk penempatan.",
                422,
            );
        }
    }

    public function assertNotInbound(string $locationId, ?string $binId, string $operation): void
    {
        $this->assertConsumable($locationId, $binId, $operation);
    }

    private function binAtLocation(string $locationId, ?string $binId, string $operation): LocationBin
    {
        if (! $binId) {
            throw new UserFacingException(
                'Rak Tidak Valid',
                "Rak wajib ditentukan untuk {$operation}.",
                422,
            );
        }

        $bin = LocationBin::query()
            ->whereKey($binId)
            ->where('location_id', $locationId)
            ->first();

        if (! $bin) {
            throw new UserFacingException(
                'Rak Tidak Valid',
                'Rak tidak ditemukan pada gudang yang dipilih.',
                422,
            );
        }

        return $bin;
    }
}
