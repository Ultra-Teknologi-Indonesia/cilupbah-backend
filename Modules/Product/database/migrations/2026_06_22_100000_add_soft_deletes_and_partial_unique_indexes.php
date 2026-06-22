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
            $table->softDeletes()->after('is_active');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')->references('id')->on('products')->onDelete('restrict');
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_unique');
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE deleted_at IS NULL');

        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_sku_unique');
        DB::statement('CREATE UNIQUE INDEX product_variants_sku_unique ON product_variants (sku) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_sku_unique');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_sku_unique UNIQUE (sku)');

        DB::statement('DROP INDEX IF EXISTS product_variants_sku_unique');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_sku_unique UNIQUE (sku)');

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
