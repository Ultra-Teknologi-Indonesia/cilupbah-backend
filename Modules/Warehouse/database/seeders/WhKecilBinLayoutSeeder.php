<?php

namespace Modules\Warehouse\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Services\BinLayoutImporter;

class WhKecilBinLayoutSeeder extends Seeder
{
    public function run(): void
    {
        $id = Location::getSmallWarehouseId();
        $location = $id ? Location::find($id) : null;
        if (! $location) {
            $this->command?->warn('WhKecilBinLayoutSeeder: Gudang kecil belum ada, dilewati (jalankan WarehouseDatabaseSeeder dulu).');
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
