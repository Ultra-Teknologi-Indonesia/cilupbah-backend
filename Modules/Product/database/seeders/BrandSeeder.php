<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(module_path('Product', 'database/data/brand.json')), true);

        $rows = array_map(fn($item) => [
            'name'       => $item['name'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $data);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('brands')->insert($chunk);
        }
    }
}
