<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    private array $externalToInternalId = [];

    public function run(): void
    {
        $existingCount = DB::table('categories')->where('source', 'system')->count();
        if ($existingCount > 0) {
            $this->command->info("CategorySeeder: {$existingCount} system categories already exist — skipping.");
            return;
        }

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
                ->where('external_id', $cat['id'])
                ->first();

            if ($existing) {
                DB::table('categories')->where('id', $existing->id)->update([
                    'name' => $cat['local_name'],
                    'is_leaf' => $cat['is_leaf'],
                    'parent_id' => $parentInternalId,
                    'source' => 'system',
                    'updated_at' => $now,
                ]);
                $id = $existing->id;
            } else {
                $id = DB::table('categories')->insertGetId([
                    'external_id' => $cat['id'],
                    'parent_id' => $parentInternalId,
                    'name' => $cat['local_name'],
                    'is_leaf' => $cat['is_leaf'],
                    'is_active' => true,
                    'source' => 'system',
                    'is_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->externalToInternalId[$cat['id']] = $id;

            if (isset($byParent[$cat['id']])) {
                $this->insertLevel($byParent, $cat['id'], $id);
            }
        }
    }
}
