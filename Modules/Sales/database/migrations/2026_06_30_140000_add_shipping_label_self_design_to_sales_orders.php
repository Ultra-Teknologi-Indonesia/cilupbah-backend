<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-Design AWB fallback (Shopee dummy J&T, SPX Instant, dll):
 *  - Tambah kolom `shipping_label_raw_data` (JSON nullable) untuk menyimpan
 *    raw payload dari Shopee `get_shipping_document_data_info` ketika channel
 *    TIDAK menyediakan PDF label dan BE harus render PDF sendiri.
 *  - Ganti tipe kolom `shipping_label_status` dari ENUM ke VARCHAR supaya
 *    bisa menambah nilai baru (`self_design_ready`) tanpa migration ALTER ENUM
 *    yang riskan di MySQL/MariaDB.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Ganti enum -> string supaya nilai status fleksibel.
        if (Schema::hasColumn('sales_orders', 'shipping_label_status')) {
            // Gunakan raw statement supaya cross-engine compatible & tidak butuh doctrine/dbal.
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement("ALTER TABLE sales_orders MODIFY shipping_label_status VARCHAR(32) NULL");
            } else {
                // Untuk pgsql/sqlite: cukup biarkan, Eloquent tetap tulis string.
                // (sqlite testing tidak punya enum strict)
            }
        }

        // 2. Tambah kolom JSON raw data.
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'shipping_label_raw_data')) {
                $table->json('shipping_label_raw_data')
                    ->nullable()
                    ->after('shipping_label_prepared_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (Schema::hasColumn('sales_orders', 'shipping_label_raw_data')) {
                $table->dropColumn('shipping_label_raw_data');
            }
        });

        // Note: tidak revert string -> enum karena bisa kehilangan data
        // (baris dengan status 'self_design_ready' akan invalid kalau enum dipulihkan).
        // Down hanya menghapus kolom JSON baru.
    }
};
