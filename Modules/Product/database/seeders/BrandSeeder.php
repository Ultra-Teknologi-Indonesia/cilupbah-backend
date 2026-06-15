<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(module_path('Product', 'database/data/brand.json')), true);

        // Idempotent: lewati merek yang sudah ada (aman re-run tiap deploy).
        $existing = DB::table('brands')->pluck('name')->all();
        $rows = [];
        foreach ($data as $item) {
            if (in_array($item['name'], $existing, true)) {
                continue;
            }
            $existing[] = $item['name'];
            $rows[] = [
                'name'       => $item['name'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('brands')->insert($chunk);
        }
    }
}
