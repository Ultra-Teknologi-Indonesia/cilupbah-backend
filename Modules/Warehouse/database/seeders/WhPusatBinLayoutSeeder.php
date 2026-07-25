<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Services\BinLayoutImporter;

class WhPusatBinLayoutSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::where('location_code', Location::SYSTEM_PUSAT_CODE)->first();
        if (! $location) {
            $this->command?->warn('WhPusatBinLayoutSeeder: lokasi WH-PUSAT belum ada, dilewati (jalankan WarehouseDatabaseSeeder dulu).');
            return;
        }

        $path = __DIR__.'/../data/wh-pusat-bin-codes.csv';
        if (! is_readable($path)) {
            $this->command?->warn("WhPusatBinLayoutSeeder: file kode rak tidak ditemukan: {$path}");
            return;
        }

        $codes = array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)));

        $result = app(BinLayoutImporter::class)->import($location, $codes, true);

        $this->command?->info(sprintf(
            'WH-PUSAT layout: %d kode total, %d dibuat, %d sudah ada, %d zona baru.',
            $result['total'],
            $result['created'],
            $result['existing'],
            $result['zones_created'],
        ));
    }
}
