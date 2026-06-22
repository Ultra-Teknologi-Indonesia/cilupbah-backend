<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultCategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Handphone & Aksesoris' => [
                'Adaptor + Kabel' => [],
                'Casing & Covers' => [
                    'Anti Crack Case' => [],
                    'Hard Case' => [],
                    'Leather Case' => [],
                    'Skin Handphone' => [],
                    'Soft Case' => [],
                ],
                'Gantungan HP' => [
                    'Gantungan HP' => [],
                ],
                'Screen Guard' => [
                    'Screen Guard' => [],
                ],
                'Smartwatch' => [
                    'Strap' => [],
                ],
                'Stick & Tripod' => [
                    'Lazypod' => [],
                    'Phone Holder' => [],
                ],
            ],
        ];

        $count = 0;
        $this->seed($tree, null, $count);
        $this->command->info("DefaultCategorySeeder: {$count} categories seeded.");
    }

    private function seed(array $children, ?int $parentId, int &$count): void
    {
        $now = now();

        foreach ($children as $name => $grandchildren) {
            $isLeaf = empty($grandchildren);

            $existing = DB::table('categories')
                ->where('name', $name)
                ->where('parent_id', $parentId)
                ->where('source', 'custom')
                ->first();

            if ($existing) {
                $id = $existing->id;
            } else {
                $id = DB::table('categories')->insertGetId([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'is_leaf' => $isLeaf,
                    'is_active' => true,
                    'is_enabled' => true,
                    'source' => 'custom',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $count++;
            }

            if (! $isLeaf) {
                $this->seed($grandchildren, $id, $count);
            }
        }
    }
}
