<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    private array $externalToInternalId = [];

    public function run(): void
    {
        $data = json_decode(
            file_get_contents(module_path('Product', 'database/data/tiktok_categories_l3.json')),
            true
        );

        $categories = $data['categories'];

        $byParent = [];
        foreach ($categories as $cat) {
            $byParent[$cat['parent_id']][] = $cat;
        }

        $this->insertLevel($byParent, '0', null);

        $this->command->info('CategorySeeder: ' . count($this->externalToInternalId) . ' categories seeded from TikTok data.');
    }

    private function insertLevel(array $byParent, string $parentExternalId, ?int $parentInternalId): void
    {
        $children = $byParent[$parentExternalId] ?? [];
        $now = now();

        foreach ($children as $cat) {
            $existing = DB::table('categories')
                ->where('name', $cat['local_name'])
                ->where(fn ($q) => $parentInternalId === null
                    ? $q->whereNull('parent_id')
                    : $q->where('parent_id', $parentInternalId))
                ->first();

            $id = $existing
                ? $existing->id
                : DB::table('categories')->insertGetId([
                    'parent_id' => $parentInternalId,
                    'name' => $cat['local_name'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $this->externalToInternalId[$cat['id']] = $id;

            if (isset($byParent[$cat['id']])) {
                $this->insertLevel($byParent, $cat['id'], $id);
            }
        }
    }
}
