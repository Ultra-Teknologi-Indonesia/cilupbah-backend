<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            // Atribut aktual produk di channel (kanonik, dari pull). Untuk deteksi
            // "Atribut Tidak Seragam" antar channel.
            $table->json('channel_attributes')->nullable()->after('external_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('channel_attributes');
        });
    }
};
