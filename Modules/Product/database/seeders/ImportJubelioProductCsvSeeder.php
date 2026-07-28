<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class ImportJubelioProductCsvSeeder extends Seeder
{
    /**
     * Jalankan seeder untuk meng-import data master produk dari Jubelio CSV.
     */
    public function run(): void
    {
        $this->command->info('Memulai ImportJubelioProductCsvSeeder...');
        Artisan::call('product:import-jubelio-csv', [], $this->command->getOutput());
    }
}
