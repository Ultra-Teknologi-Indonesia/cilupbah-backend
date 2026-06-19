<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Channel\Models\Channel;
use Ramsey\Uuid\Uuid;

class MasterDataSeeder extends Seeder
{
    private array $externalToInternalCat = [];
    private array $externalToChannelCatUuid = [];

    // Phase B lookups
    private array $attrKeyToInternalId = [];
    private array $optionKeyToInternalId = [];
    private array $channelAttrExtToUuid = [];
    private array $channelOptExtToUuid = [];

    public function run(): void
    {
        ini_set('memory_limit', '512M');
        DB::disableQueryLog();

        $tiktokChannel = Channel::where('code', 'tiktok')->first();

        if (! $tiktokChannel) {
            $this->command->warn('TikTok channel not found. Aborting MasterDataSeeder.');
            return;
        }

        $categoriesJson = json_decode(
            file_get_contents(module_path('Product', 'database/data/tiktok_categories_l3.json')),
            true
        );

        $rulesJson = json_decode(
            file_get_contents(module_path('Product', 'database/data/tiktok_category_rules.json')),
            true
        );

        $this->command->info('Phase A: Categories');

        $this->seedInternalCategories($categoriesJson['categories']);
        $this->seedChannelCategories($categoriesJson['categories'], $tiktokChannel->id);
        $this->seedRules($rulesJson['rules'], $tiktokChannel->id);
        $this->seedCategoryChannelMappings();

        $this->command->info('Phase A complete.');
        $this->command->table(
            ['Table', 'Count'],
            [
                ['categories', DB::table('categories')->count()],
                ['channel_categories', DB::table('channel_categories')->where('channel_id', $tiktokChannel->id)->count()],
                ['channel_categories (with rules)', DB::table('channel_categories')->where('channel_id', $tiktokChannel->id)->whereNotNull('rules')->count()],
                ['category_channel_mappings', DB::table('category_channel_mappings')->count()],
            ]
        );

        // --- Phase B: Attributes ---
        $attributesJson = json_decode(
            file_get_contents(module_path('Product', 'database/data/tiktok_category_attributes.json')),
            true
        );

        $this->command->info('Phase B: Attributes');

        $uniqueAttrs = $this->collectUniqueAttributes($attributesJson['categories']);
        $this->truncateAttributeTables();
        $this->seedInternalAttributes($uniqueAttrs);
        $this->seedInternalAttributeOptions($uniqueAttrs);
        $this->seedChannelAttributes($attributesJson['categories']);
        $this->seedChannelAttributeOptions($attributesJson['categories']);
        $this->seedCategoryAttributesPivot($attributesJson['categories']);
        $this->seedAttributeChannelMappings($attributesJson['categories']);
        $this->seedAttributeOptionChannelMappings($attributesJson['categories']);

        $this->command->info('Phase B complete.');
        $this->command->table(
            ['Table', 'Count'],
            [
                ['attributes', DB::table('attributes')->count()],
                ['attribute_options', DB::table('attribute_options')->count()],
                ['channel_attributes', DB::table('channel_attributes')->count()],
                ['channel_attribute_options', DB::table('channel_attribute_options')->count()],
                ['category_attributes', DB::table('category_attributes')->count()],
                ['attribute_channel_mappings', DB::table('attribute_channel_mappings')->count()],
                ['attribute_option_channel_mappings', DB::table('attribute_option_channel_mappings')->count()],
            ]
        );
    }

    private function seedInternalCategories(array $categories): void
    {
        $this->command->info('  A1: Seeding internal categories...');

        DB::table('attribute_option_channel_mappings')->truncate();
        DB::table('attribute_channel_mappings')->truncate();
        DB::table('product_specifications')->truncate();
        DB::table('product_variation_types')->truncate();
        DB::table('channel_attribute_options')->truncate();
        DB::table('channel_attributes')->truncate();
        DB::table('attribute_options')->truncate();
        DB::table('category_attributes')->truncate();
        DB::table('attributes')->truncate();
        DB::table('category_channel_mappings')->truncate();
        DB::table('channel_categories')->truncate();
        DB::table('categories')->truncate();

        $byParent = [];
        foreach ($categories as $cat) {
            $byParent[$cat['parent_id']][] = $cat;
        }

        $this->insertCategoryLevel($byParent, '0', null);

        $this->command->info('    → ' . count($this->externalToInternalCat) . ' internal categories seeded.');
    }

    private function insertCategoryLevel(array $byParent, string $parentExternalId, ?int $parentInternalId): void
    {
        $children = $byParent[$parentExternalId] ?? [];
        $now = now();

        foreach ($children as $cat) {
            $internalId = DB::table('categories')->insertGetId([
                'external_id' => $cat['id'],
                'parent_id' => $parentInternalId,
                'name' => $cat['local_name'],
                'is_leaf' => $cat['is_leaf'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->externalToInternalCat[$cat['id']] = $internalId;

            if (isset($byParent[$cat['id']])) {
                $this->insertCategoryLevel($byParent, $cat['id'], $internalId);
            }
        }
    }

    private function seedChannelCategories(array $categories, string $channelId): void
    {
        $this->command->info('  A2: Seeding channel categories...');

        $now = now();
        $chunks = array_chunk($categories, 500);

        foreach ($chunks as $chunk) {
            $rows = [];
            foreach ($chunk as $cat) {
                $uuid = Uuid::uuid7()->toString();
                $this->externalToChannelCatUuid[$cat['id']] = $uuid;

                $rows[] = [
                    'id' => $uuid,
                    'channel_id' => $channelId,
                    'external_id' => $cat['id'],
                    'parent_external_id' => $cat['parent_id'],
                    'name' => $cat['local_name'],
                    'is_leaf' => $cat['is_leaf'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('channel_categories')->insert($rows);
        }

        $this->command->info('    → ' . count($this->externalToChannelCatUuid) . ' channel categories seeded.');
    }

    private function seedRules(array $rules, string $channelId): void
    {
        $this->command->info('  A3: Seeding rules...');

        $count = 0;
        foreach ($rules as $externalId => $entry) {
            $uuid = $this->externalToChannelCatUuid[$externalId] ?? null;
            if (! $uuid) {
                continue;
            }

            DB::table('channel_categories')
                ->where('id', $uuid)
                ->update(['rules' => json_encode($entry['rules'])]);

            $count++;
        }

        $this->command->info("    → {$count} rules applied.");
    }

    private function seedCategoryChannelMappings(): void
    {
        $this->command->info('  A4: Seeding category ↔ channel mappings...');

        $now = now();
        $rows = [];

        foreach ($this->externalToInternalCat as $externalId => $internalId) {
            $channelUuid = $this->externalToChannelCatUuid[$externalId] ?? null;
            if (! $channelUuid) {
                continue;
            }

            $rows[] = [
                'category_id' => $internalId,
                'channel_category_id' => $channelUuid,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $chunks = array_chunk($rows, 500);
        foreach ($chunks as $chunk) {
            DB::table('category_channel_mappings')->insert($chunk);
        }

        $this->command->info('    → ' . count($rows) . ' mappings created.');
    }

    // =========================================================================
    // Phase B: Attributes
    // =========================================================================

    private function makeAttrKey(string $name, string $type): string
    {
        return mb_strtolower($name) . '|' . $type;
    }

    private function mapAttrType(string $tiktokType): string
    {
        return $tiktokType === 'SALES_PROPERTY' ? 'sales' : 'spec';
    }

    /**
     * B1: First pass — collect unique attributes + merged options.
     */
    private function collectUniqueAttributes(array $categories): array
    {
        $this->command->info('  B1: Collecting unique attributes...');

        $unique = [];

        foreach ($categories as $cat) {
            foreach ($cat['attributes'] as $attr) {
                $type = $this->mapAttrType($attr['type']);
                $key = $this->makeAttrKey($attr['name'], $type);

                if (! isset($unique[$key])) {
                    $unique[$key] = [
                        'name' => $attr['name'],
                        'type' => $type,
                        'options' => [],
                    ];
                }

                foreach ($attr['values'] ?? [] as $v) {
                    $optKey = mb_strtolower($v['name']);
                    if (! isset($unique[$key]['options'][$optKey])) {
                        $unique[$key]['options'][$optKey] = $v['name'];
                    }
                }
            }
        }

        $totalOpts = 0;
        foreach ($unique as $a) {
            $totalOpts += count($a['options']);
        }

        $this->command->info("    → " . count($unique) . " unique attributes, {$totalOpts} unique options.");

        return $unique;
    }

    private function truncateAttributeTables(): void
    {
        $this->command->info('  B2: Attribute tables already truncated in A1, skipping...');
    }

    /**
     * B2: Seed internal attributes (deduplicated).
     */
    private function seedInternalAttributes(array $uniqueAttrs): void
    {
        $this->command->info('  B2: Seeding internal attributes...');

        $now = now();
        foreach ($uniqueAttrs as $key => $attr) {
            $id = DB::table('attributes')->insertGetId([
                'name' => $attr['name'],
                'type' => $attr['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->attrKeyToInternalId[$key] = $id;
        }

        $this->command->info('    → ' . count($this->attrKeyToInternalId) . ' internal attributes seeded.');
    }

    /**
     * B3: Seed internal attribute_options (deduplicated across categories).
     */
    private function seedInternalAttributeOptions(array $uniqueAttrs): void
    {
        $this->command->info('  B3: Seeding internal attribute options...');

        $now = now();
        $count = 0;
        $batch = [];

        foreach ($uniqueAttrs as $attrKey => $attr) {
            $attrId = $this->attrKeyToInternalId[$attrKey];

            foreach ($attr['options'] as $optKey => $optName) {
                $batch[] = [
                    'attribute_id' => $attrId,
                    'value' => $optName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($batch) >= 500) {
                    DB::table('attribute_options')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            DB::table('attribute_options')->insert($batch);
        }

        // Build lookup: "attrId|lowercaseValue" → option id
        DB::table('attribute_options')
            ->select('id', 'attribute_id', 'value')
            ->orderBy('id')
            ->chunk(1000, function ($rows) {
                foreach ($rows as $row) {
                    $key = $row->attribute_id . '|' . mb_strtolower($row->value);
                    $this->optionKeyToInternalId[$key] = $row->id;
                }
            });

        $this->command->info("    → {$count} internal options seeded.");
    }

    /**
     * B4: Seed channel_attributes (one per category×attribute in JSON).
     */
    private function seedChannelAttributes(array $categories): void
    {
        $this->command->info('  B4: Seeding channel attributes...');

        $now = now();
        $count = 0;
        $batch = [];

        foreach ($categories as $catExtId => $cat) {
            $channelCatUuid = $this->externalToChannelCatUuid[$catExtId] ?? null;
            if (! $channelCatUuid) {
                continue;
            }

            foreach ($cat['attributes'] as $attr) {
                $uuid = Uuid::uuid7()->toString();
                // Key: "catExtId|attrExtId" for later lookup
                $this->channelAttrExtToUuid[$catExtId . '|' . $attr['id']] = $uuid;

                $batch[] = [
                    'id' => $uuid,
                    'channel_category_id' => $channelCatUuid,
                    'external_id' => $attr['id'],
                    'name' => $attr['name'],
                    'is_required' => $attr['is_requried'] ?? false,
                    'is_multiple' => $attr['is_multiple_selection'] ?? false,
                    'is_sale_prop' => $attr['type'] === 'SALES_PROPERTY',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($batch) >= 500) {
                    DB::table('channel_attributes')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            DB::table('channel_attributes')->insert($batch);
        }

        $this->command->info("    → {$count} channel attributes seeded.");
    }

    /**
     * B5: Seed channel_attribute_options.
     */
    private function seedChannelAttributeOptions(array $categories): void
    {
        $this->command->info('  B5: Seeding channel attribute options...');

        $now = now();
        $count = 0;
        $batch = [];

        foreach ($categories as $catExtId => $cat) {
            foreach ($cat['attributes'] as $attr) {
                $channelAttrUuid = $this->channelAttrExtToUuid[$catExtId . '|' . $attr['id']] ?? null;
                if (! $channelAttrUuid) {
                    continue;
                }

                foreach ($attr['values'] ?? [] as $v) {
                    $uuid = Uuid::uuid7()->toString();
                    $this->channelOptExtToUuid[$catExtId . '|' . $attr['id'] . '|' . $v['id']] = $uuid;

                    $batch[] = [
                        'id' => $uuid,
                        'channel_attribute_id' => $channelAttrUuid,
                        'external_id' => $v['id'],
                        'name' => $v['name'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $count++;

                    if (count($batch) >= 500) {
                        DB::table('channel_attribute_options')->insert($batch);
                        $batch = [];
                    }
                }
            }
        }

        if (! empty($batch)) {
            DB::table('channel_attribute_options')->insert($batch);
        }

        $this->command->info("    → {$count} channel attribute options seeded.");
    }

    /**
     * B6: Seed category_attributes pivot (internal category ↔ internal attribute).
     */
    private function seedCategoryAttributesPivot(array $categories): void
    {
        $this->command->info('  B6: Seeding category_attributes pivot...');

        $now = now();
        $count = 0;
        $batch = [];
        $seen = [];

        foreach ($categories as $catExtId => $cat) {
            $internalCatId = $this->externalToInternalCat[$catExtId] ?? null;
            if (! $internalCatId) {
                continue;
            }

            foreach ($cat['attributes'] as $attr) {
                $type = $this->mapAttrType($attr['type']);
                $attrKey = $this->makeAttrKey($attr['name'], $type);
                $internalAttrId = $this->attrKeyToInternalId[$attrKey] ?? null;
                if (! $internalAttrId) {
                    continue;
                }

                $pivotKey = $internalCatId . '|' . $internalAttrId;
                if (isset($seen[$pivotKey])) {
                    continue;
                }
                $seen[$pivotKey] = true;

                $batch[] = [
                    'category_id' => $internalCatId,
                    'attribute_id' => $internalAttrId,
                    'is_required' => $attr['is_requried'] ?? false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($batch) >= 500) {
                    DB::table('category_attributes')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            DB::table('category_attributes')->insert($batch);
        }

        $this->command->info("    → {$count} category↔attribute pivots created.");
    }

    /**
     * B7: Seed attribute_channel_mappings (internal attr ↔ channel attr).
     */
    private function seedAttributeChannelMappings(array $categories): void
    {
        $this->command->info('  B7: Seeding attribute ↔ channel mappings...');

        $now = now();
        $count = 0;
        $batch = [];

        foreach ($categories as $catExtId => $cat) {
            foreach ($cat['attributes'] as $attr) {
                $type = $this->mapAttrType($attr['type']);
                $attrKey = $this->makeAttrKey($attr['name'], $type);
                $internalAttrId = $this->attrKeyToInternalId[$attrKey] ?? null;
                $channelAttrUuid = $this->channelAttrExtToUuid[$catExtId . '|' . $attr['id']] ?? null;

                if (! $internalAttrId || ! $channelAttrUuid) {
                    continue;
                }

                $batch[] = [
                    'attribute_id' => $internalAttrId,
                    'channel_attribute_id' => $channelAttrUuid,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $count++;

                if (count($batch) >= 500) {
                    DB::table('attribute_channel_mappings')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (! empty($batch)) {
            DB::table('attribute_channel_mappings')->insert($batch);
        }

        $this->command->info("    → {$count} attribute mappings created.");
    }

    /**
     * B8: Seed attribute_option_channel_mappings.
     */
    private function seedAttributeOptionChannelMappings(array $categories): void
    {
        $this->command->info('  B8: Seeding attribute option ↔ channel mappings...');

        $now = now();
        $count = 0;
        $batch = [];

        foreach ($categories as $catExtId => $cat) {
            foreach ($cat['attributes'] as $attr) {
                $type = $this->mapAttrType($attr['type']);
                $attrKey = $this->makeAttrKey($attr['name'], $type);
                $internalAttrId = $this->attrKeyToInternalId[$attrKey] ?? null;

                if (! $internalAttrId) {
                    continue;
                }

                foreach ($attr['values'] ?? [] as $v) {
                    $channelOptUuid = $this->channelOptExtToUuid[$catExtId . '|' . $attr['id'] . '|' . $v['id']] ?? null;
                    $optionLookup = $internalAttrId . '|' . mb_strtolower($v['name']);
                    $internalOptId = $this->optionKeyToInternalId[$optionLookup] ?? null;

                    if (! $channelOptUuid || ! $internalOptId) {
                        continue;
                    }

                    $batch[] = [
                        'attribute_option_id' => $internalOptId,
                        'channel_attribute_option_id' => $channelOptUuid,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $count++;

                    if (count($batch) >= 500) {
                        DB::table('attribute_option_channel_mappings')->insert($batch);
                        $batch = [];
                    }
                }
            }
        }

        if (! empty($batch)) {
            DB::table('attribute_option_channel_mappings')->insert($batch);
        }

        $this->command->info("    → {$count} option mappings created.");
    }
}
