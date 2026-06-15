<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(file_get_contents(module_path('Product', 'database/data/category.json')), true);
        $this->insertCategories($data, null);
    }

    private function insertCategories(array $items, ?int $parentId): void
    {
        foreach ($items as $item) {
            // Idempotent: cocokkan berdasarkan (name, parent_id) agar aman re-run.
            $query = DB::table('categories')->where('name', $item['name']);
            $parentId === null
                ? $query->whereNull('parent_id')
                : $query->where('parent_id', $parentId);

            $existing = $query->first();

            $id = $existing
                ? $existing->id
                : DB::table('categories')->insertGetId([
                    'parent_id'  => $parentId,
                    'name'       => $item['name'],
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (!empty($item['children'])) {
                $this->insertCategories($item['children'], $id);
            }
        }
    }
}
