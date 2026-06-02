<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AttributeSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(module_path('Product', 'database/data/attribute.json')), true);

        $rows = array_map(fn($item) => [
            'name'       => $item['name'],
            'type'       => $item['type'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $data);

        DB::table('attributes')->insert($rows);
    }
}
