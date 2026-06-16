<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fase A: is_sale_prop menandai atribut channel sebagai sale-property (varian)
     * vs atribut deskriptif (spesifikasi). Dipakai materialize untuk menentukan
     * type atribut internal (sales = jenis varian, spec = spesifikasi).
     */
    public function up(): void
    {
        Schema::table('channel_attributes', function (Blueprint $table) {
            $table->boolean('is_sale_prop')->default(false)->after('is_multiple');
        });
    }

    public function down(): void
    {
        Schema::table('channel_attributes', function (Blueprint $table) {
            $table->dropColumn('is_sale_prop');
        });
    }
};
