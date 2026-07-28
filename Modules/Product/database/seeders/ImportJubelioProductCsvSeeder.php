<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class ImportJubelioProductCsvSeeder extends Seeder
{

    public function run(): void
    {
        $this->command->info('Memulai ImportJubelioProductCsvSeeder...');
        Artisan::call('product:import-jubelio-csv', [], $this->command->getOutput());
    }
}
