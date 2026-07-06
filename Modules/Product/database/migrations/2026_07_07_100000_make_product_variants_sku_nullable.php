<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });

        DB::statement('DROP INDEX IF EXISTS product_variants_sku_unique');
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_unique ON product_variants (sku) WHERE deleted_at IS NULL AND sku IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE product_variants SET sku = 'LEGACY-' || SUBSTRING(id::text, 1, 12) WHERE sku IS NULL");

        DB::statement('DROP INDEX IF EXISTS product_variants_sku_unique');
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_unique ON product_variants (sku) WHERE deleted_at IS NULL');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });
    }
};
