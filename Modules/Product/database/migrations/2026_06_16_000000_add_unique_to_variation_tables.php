<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jaring DB untuk invariant varian:
     *  - satu jenis varian (attribute_id) hanya boleh sekali per produk;
     *  - satu opsi (attribute_id) hanya boleh sekali per varian.
     * Enforcement "maks 2 jenis" tetap di FormRequest + ProductService
     * (tidak bisa diekspresikan sebagai unique/constraint sederhana).
     */
    public function up(): void
    {
        // Bersihkan duplikat lama (jika ada) sebelum memasang unique, simpan id terkecil.
        DB::statement('
            DELETE FROM product_variation_types a
            USING product_variation_types b
            WHERE a.id > b.id
              AND a.product_id = b.product_id
              AND a.attribute_id = b.attribute_id
        ');
        DB::statement('
            DELETE FROM variant_options a
            USING variant_options b
            WHERE a.id > b.id
              AND a.variant_id = b.variant_id
              AND a.attribute_id = b.attribute_id
        ');

        Schema::table('product_variation_types', function (Blueprint $table) {
            $table->unique(['product_id', 'attribute_id'], 'uniq_pvt_product_attribute');
        });

        Schema::table('variant_options', function (Blueprint $table) {
            $table->unique(['variant_id', 'attribute_id'], 'uniq_vo_variant_attribute');
        });
    }

    public function down(): void
    {
        Schema::table('product_variation_types', function (Blueprint $table) {
            $table->dropUnique('uniq_pvt_product_attribute');
        });

        Schema::table('variant_options', function (Blueprint $table) {
            $table->dropUnique('uniq_vo_variant_attribute');
        });
    }
};
