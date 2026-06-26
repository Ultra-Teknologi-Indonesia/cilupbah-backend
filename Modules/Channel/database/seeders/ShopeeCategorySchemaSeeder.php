<?php

namespace Modules\Channel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Seeds the Shopee category / attribute / variation schema LOCALLY so Shopee
 * has the same offline coverage TikTok already has in the dev DB — without
 * needing a connected Shopee shop or a live API call.
 *
 * It mirrors exactly what `ShopeeProductService::syncCategoryTree` /
 * `syncCategoryAttributes` would persist (same tables / columns):
 *   - channel_categories          (Shopee leaf categories)
 *   - channel_attributes          (spec attrs + sale-props via is_sale_prop)
 *   - channel_attribute_options   (option values)
 *   - category_channel_mappings   (custom "Handphone & Aksesoris" leaf -> Shopee)
 *
 * Once seeded, the existing `shopee:sync-category-attributes {shop_id}` command
 * will refresh these same rows from the live API when a real shop is connected.
 *
 * Idempotent: keyed by the natural unique keys the live sync uses.
 */
class ShopeeCategorySchemaSeeder extends Seeder
{
    /**
     * Custom leaf category name => Shopee category external_id.
     * Screen Guard -> 400280 matches the mapping already used on staging.
     */
    private const CATEGORIES = [
        'Adaptor + Kabel' => '100640',
        'Anti Crack Case' => '100641',
        'Hard Case'       => '100642',
        'Leather Case'    => '100643',
        'Skin Handphone'  => '100644',
        'Soft Case'       => '100645',
        'Gantungan HP'    => '100646',
        'Screen Guard'    => '400280',
        'Strap'           => '100648',
        'Lazypod'         => '100649',
        'Phone Holder'    => '100650',
    ];

    /**
     * Attribute templates. Each is applied to every Shopee category above.
     * is_sale_prop=true marks a variation axis (Shopee "tier variation").
     * external_id only needs to be unique within a category, so fixed ids are fine.
     */
    private const ATTRIBUTES = [
        ['ext' => '100001', 'name' => 'Merek', 'required' => true, 'multiple' => false, 'sale_prop' => false,
            'options' => ['ANKER', 'ACOME', 'Apple', 'ADVAN', 'ALDO', 'ARASHI', 'AILITE', '3M', 'OEM']],
        ['ext' => '100002', 'name' => 'Garansi', 'required' => false, 'multiple' => false, 'sale_prop' => false,
            'options' => ['Tidak Ada Garansi', '1 Bulan', '3 Bulan', '1 Tahun']],
        ['ext' => '100003', 'name' => 'Bahan', 'required' => false, 'multiple' => true, 'sale_prop' => false,
            'options' => ['Silikon', 'TPU', 'Akrilik', 'Kulit', 'Kaca Tempered', 'Aluminium', 'Nilon']],
        // --- Sale properties (variations) ---
        ['ext' => '100100', 'name' => 'Warna', 'required' => false, 'multiple' => false, 'sale_prop' => true,
            'options' => ['Hitam', 'Putih', 'Navy', 'Merah', 'Pink', 'Bening', 'Cokelat']],
        ['ext' => '100101', 'name' => 'Ukuran', 'required' => false, 'multiple' => false, 'sale_prop' => true,
            'options' => ['S', 'M', 'L', '41mm', '45mm']],
    ];

    public function run(): void
    {
        $channelId = $this->channelId();

        $catCount = 0;
        $attrCount = 0;
        $optCount = 0;
        $mapCount = 0;

        foreach (self::CATEGORIES as $name => $extId) {
            $channelCategoryId = $this->upsertCategory($channelId, $extId, $name);
            $catCount++;

            foreach (self::ATTRIBUTES as $attr) {
                $channelAttributeId = $this->upsertAttribute($channelCategoryId, $attr);
                $attrCount++;

                foreach ($attr['options'] as $i => $optName) {
                    $this->upsertOption($channelAttributeId, $attr['ext'] . '-' . ($i + 1), $optName);
                    $optCount++;
                }
            }

            $mapCount += $this->mapCustomCategory($name, $channelCategoryId);
        }

        $this->command->info("ShopeeCategorySchemaSeeder: {$catCount} categories, {$attrCount} attributes, {$optCount} options, {$mapCount} custom mappings.");
    }

    private function upsertCategory(string $channelId, string $extId, string $name): string
    {
        $existing = DB::table('channel_categories')
            ->where('channel_id', $channelId)
            ->where('external_id', $extId)
            ->first();

        $values = [
            'parent_external_id' => '0',
            'name' => $name,
            'is_leaf' => true,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('channel_categories')->where('id', $existing->id)->update($values);

            return $existing->id;
        }

        $id = Uuid::uuid7()->toString();
        DB::table('channel_categories')->insert($values + [
            'id' => $id,
            'channel_id' => $channelId,
            'external_id' => $extId,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function upsertAttribute(string $channelCategoryId, array $attr): string
    {
        $existing = DB::table('channel_attributes')
            ->where('channel_category_id', $channelCategoryId)
            ->where('external_id', $attr['ext'])
            ->first();

        $values = [
            'name' => $attr['name'],
            'is_required' => $attr['required'],
            'is_multiple' => $attr['multiple'],
            'is_sale_prop' => $attr['sale_prop'],
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('channel_attributes')->where('id', $existing->id)->update($values);

            return $existing->id;
        }

        $id = Uuid::uuid7()->toString();
        DB::table('channel_attributes')->insert($values + [
            'id' => $id,
            'channel_category_id' => $channelCategoryId,
            'external_id' => $attr['ext'],
            'created_at' => now(),
        ]);

        return $id;
    }

    private function upsertOption(string $channelAttributeId, string $extId, string $name): void
    {
        $existing = DB::table('channel_attribute_options')
            ->where('channel_attribute_id', $channelAttributeId)
            ->where('external_id', $extId)
            ->first();

        $values = ['name' => $name, 'updated_at' => now()];

        if ($existing) {
            DB::table('channel_attribute_options')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('channel_attribute_options')->insert($values + [
            'id' => Uuid::uuid7()->toString(),
            'channel_attribute_id' => $channelAttributeId,
            'external_id' => $extId,
            'created_at' => now(),
        ]);
    }

    /** Map the existing custom leaf category (by name) -> Shopee channel category. */
    private function mapCustomCategory(string $name, string $channelCategoryId): int
    {
        $categoryId = DB::table('categories')
            ->where('name', $name)
            ->where('source', 'custom')
            ->where('is_leaf', true)
            ->value('id');

        if (! $categoryId) {
            $this->command->warn("  custom leaf '{$name}' not found — skipping Shopee mapping.");

            return 0;
        }

        $exists = DB::table('category_channel_mappings')
            ->where('category_id', $categoryId)
            ->where('channel_category_id', $channelCategoryId)
            ->exists();

        if ($exists) {
            return 0;
        }

        DB::table('category_channel_mappings')->insert([
            'category_id' => $categoryId,
            'channel_category_id' => $channelCategoryId,
            'is_stale' => false,
            'last_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 1;
    }

    private function channelId(): string
    {
        $id = DB::table('channels')->where('code', 'shopee')->value('id');

        if (! $id) {
            $id = Uuid::uuid7()->toString();
            DB::table('channels')->insert([
                'id' => $id,
                'code' => 'shopee',
                'name' => 'Shopee',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
