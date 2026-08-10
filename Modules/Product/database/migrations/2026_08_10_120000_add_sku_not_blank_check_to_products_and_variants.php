<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE products SET sku = NULL WHERE sku = ''");
        DB::statement("UPDATE product_variants SET sku = NULL WHERE sku = ''");

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_sku_not_blank CHECK (sku IS NULL OR sku <> '')");
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_sku_not_blank CHECK (sku IS NULL OR sku <> '')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_sku_not_blank');
        DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_sku_not_blank');
    }
};
