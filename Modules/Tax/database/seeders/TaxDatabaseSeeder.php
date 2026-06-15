<?php

namespace Modules\Tax\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tax\Models\Tax;

class TaxDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $taxes = [
            ['name' => 'No Tax', 'rate' => 0],
            ['name' => 'PPH 22', 'rate' => 1.5],
            ['name' => 'PPN 10%', 'rate' => 10],
            ['name' => 'PPN 11%', 'rate' => 11],
            ['name' => 'PPN 12%', 'rate' => 12],
        ];

        foreach ($taxes as $tax) {
            Tax::firstOrCreate(
                ['name' => $tax['name']],
                $tax + ['is_active' => true]
            );
        }
    }
}
