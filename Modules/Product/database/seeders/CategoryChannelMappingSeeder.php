<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class CategoryChannelMappingSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('channels:import-category-mappings');

        if ($this->command) {
            $this->command->getOutput()->write(Artisan::output());
        }
    }
}
