<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auto-mapping channel shop → gudang default tidak selalu punya
     * channel_location_id (seller single-warehouse di Shopee/TikTok tidak
     * perlu kirim location_id ke API). Buat kolom nullable agar
     * ChannelStockResolver bisa auto-insert row tanpa pelanggaran constraint.
     */
    public function up(): void
    {
        Schema::table('channel_warehouses', function (Blueprint $table) {
            $table->string('channel_location_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_warehouses', function (Blueprint $table) {
            $table->string('channel_location_id', 255)->nullable(false)->change();
        });
    }
};
