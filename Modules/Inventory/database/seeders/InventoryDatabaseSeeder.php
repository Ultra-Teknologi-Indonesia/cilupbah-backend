<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;

class InventoryDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PutawaySeeder::class);
    }
}
