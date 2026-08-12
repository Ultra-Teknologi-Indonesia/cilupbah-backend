<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement("
            DELETE FROM product_variant_channel_mappings a
            USING product_variant_channel_mappings b
            WHERE a.external_sku_id IS NOT NULL
              AND a.product_channel_mapping_id = b.product_channel_mapping_id
              AND a.external_sku_id = b.external_sku_id
              AND (a.updated_at, a.id) < (b.updated_at, b.id)
        ");

        DB::statement('ALTER TABLE product_variant_channel_mappings DROP CONSTRAINT IF EXISTS pvcm_unique');
        DB::statement('DROP INDEX IF EXISTS pvcm_unique');

        DB::statement("
            CREATE UNIQUE INDEX pvcm_unique
            ON product_variant_channel_mappings
            (product_channel_mapping_id, variant_id, COALESCE(external_sku_id, ''))
        ");

        DB::statement('
            CREATE UNIQUE INDEX pvcm_model_unique
            ON product_variant_channel_mappings (product_channel_mapping_id, external_sku_id)
            WHERE external_sku_id IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pvcm_model_unique');
        DB::statement('ALTER TABLE product_variant_channel_mappings DROP CONSTRAINT IF EXISTS pvcm_unique');
        DB::statement('DROP INDEX IF EXISTS pvcm_unique');

        DB::statement('
            DELETE FROM product_variant_channel_mappings a
            USING product_variant_channel_mappings b
            WHERE a.product_channel_mapping_id = b.product_channel_mapping_id
              AND a.variant_id = b.variant_id
              AND (a.updated_at, a.id) < (b.updated_at, b.id)
        ');

        DB::statement('
            CREATE UNIQUE INDEX pvcm_unique
            ON product_variant_channel_mappings (product_channel_mapping_id, variant_id)
        ');
    }
};
