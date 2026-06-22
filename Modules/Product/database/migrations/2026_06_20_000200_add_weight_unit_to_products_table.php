<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Satuan berat pilihan user: 'gram' atau 'kg'. Nilai `weight` disimpan
            // dalam satuan ini; dikonversi ke kg saat push ke channel.
            $table->string('weight_unit', 8)->default('kg')->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('weight_unit');
        });
    }
};
