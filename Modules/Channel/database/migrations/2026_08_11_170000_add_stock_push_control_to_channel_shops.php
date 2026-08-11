<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Memisahkan kendali push stok dari is_shadow_mode.
 *
 * Sebelumnya is_shadow_mode mengendalikan dua hal sekaligus: mode order shadow
 * dan blokir push stok ke marketplace. Akibatnya cutover order akan langsung
 * menyalakan push stok, padahal serah terima stok direncanakan menyusul per
 * toko. Sejak sekarang keduanya terpisah, sehingga urutan order dulu lalu stok
 * bisa dijalankan tanpa memaksa keduanya terjadi bersamaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->boolean('stock_push_enabled')->default(true)->after('shadow_last_pulled_at');
            $table->unsignedInteger('stock_push_buffer')->default(0)->after('stock_push_enabled');
            $table->timestamp('stock_handover_at')->nullable()->after('stock_push_buffer');
        });

        // Toko yang sedang shadow belum boleh push stok; toko lain tetap seperti sebelumnya.
        DB::table('channel_shops')
            ->where('is_shadow_mode', true)
            ->update(['stock_push_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropColumn(['stock_push_enabled', 'stock_push_buffer', 'stock_handover_at']);
        });
    }
};
