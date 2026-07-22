<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Services\BinLayoutImporter;

/**
 * Seed kode rak WH-KECIL sesuai layout Jubelio client (bundled di
 * database/data/wh-kecil-bin-codes.csv). Aditif & idempoten — aman dijalankan
 * ulang. Hanya membuat kode rak + zona; tidak menyentuh stok.
 *
 * Tujuan: saat provisioning VPS production, kode rak sudah tersedia sesuai
 * barcode fisik client tanpa perlu import CSV manual.
 */
class WhKecilBinLayoutSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::where('location_code', Location::SYSTEM_KECIL_CODE)->first();
        if (! $location) {
            $this->command?->warn('WhKecilBinLayoutSeeder: lokasi WH-KECIL belum ada, dilewati (jalankan WarehouseDatabaseSeeder dulu).');
            return;
        }

        $path = __DIR__.'/../data/wh-kecil-bin-codes.csv';
        if (! is_readable($path)) {
            $this->command?->warn("WhKecilBinLayoutSeeder: file kode rak tidak ditemukan: {$path}");
            return;
        }

        $codes = array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));

        $result = app(BinLayoutImporter::class)->import($location, $codes, true);

        $this->command?->info(sprintf(
            'WH-KECIL layout: %d kode total, %d dibuat, %d sudah ada, %d zona baru.',
            $result['total'],
            $result['created'],
            $result['existing'],
            $result['zones_created'],
        ));
    }
}
