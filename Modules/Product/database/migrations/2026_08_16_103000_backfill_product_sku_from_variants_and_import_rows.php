<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement("
            UPDATE products
            SET sku = pv.sku
            FROM (
                SELECT product_id, sku, ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY sequence_item NULLS LAST, created_at) as rn
                FROM product_variants
                WHERE sku IS NOT NULL AND TRIM(sku) != '' AND deleted_at IS NULL
            ) pv
            WHERE products.id = pv.product_id 
              AND products.is_bundle = true
              AND pv.rn = 1 
              AND (products.sku IS NULL OR TRIM(products.sku) = '')
        ");

        DB::statement("
            UPDATE products
            SET sku = pir.sku
            FROM (
                SELECT sku, name, ROW_NUMBER() OVER (PARTITION BY name ORDER BY created_at DESC) as rn
                FROM product_import_rows
                WHERE sku IS NOT NULL AND TRIM(sku) != '' AND status = 'success'
            ) pir
            WHERE products.name = pir.name 
              AND products.is_bundle = true
              AND pir.rn = 1 
              AND (products.sku IS NULL OR TRIM(products.sku) = '')
        ");

        DB::statement("
            UPDATE products
            SET sku = NULL
            WHERE is_bundle = false
              AND (SELECT COUNT(*) FROM product_variants WHERE product_id = products.id AND deleted_at IS NULL) > 1
        ");
    }

    public function down(): void
    {

    }
};
