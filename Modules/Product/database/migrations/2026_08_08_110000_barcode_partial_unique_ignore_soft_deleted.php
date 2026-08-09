<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_barcode_unique');
        DB::statement('DROP INDEX IF EXISTS product_variants_barcode_unique');
        DB::statement('CREATE UNIQUE INDEX product_variants_barcode_unique ON product_variants (barcode) WHERE deleted_at IS NULL AND barcode IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS product_variants_barcode_unique');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_barcode_unique UNIQUE (barcode)');
    }
};
