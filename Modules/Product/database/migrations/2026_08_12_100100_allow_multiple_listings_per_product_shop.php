<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE product_channel_mappings DROP CONSTRAINT IF EXISTS pcm_product_shop_unique');
        DB::statement('DROP INDEX IF EXISTS pcm_product_shop_unique');

        DB::statement("
            CREATE UNIQUE INDEX pcm_product_shop_unique
            ON product_channel_mappings
            (product_id, channel_shop_id, COALESCE(external_product_id, ''))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pcm_product_shop_unique');

        DB::statement('
            DELETE FROM product_channel_mappings a
            USING product_channel_mappings b
            WHERE a.product_id = b.product_id
              AND a.channel_shop_id = b.channel_shop_id
              AND (a.updated_at, a.id) < (b.updated_at, b.id)
        ');

        DB::statement('
            ALTER TABLE product_channel_mappings
            ADD CONSTRAINT pcm_product_shop_unique UNIQUE (product_id, channel_shop_id)
        ');
    }
};
