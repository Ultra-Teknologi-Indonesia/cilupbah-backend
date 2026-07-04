<?php

namespace Modules\Sales\Database\Seeders;

use Illuminate\Database\Seeder;

class SalesDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            InternalStoreSeeder::class,
        ]);
    }
}
