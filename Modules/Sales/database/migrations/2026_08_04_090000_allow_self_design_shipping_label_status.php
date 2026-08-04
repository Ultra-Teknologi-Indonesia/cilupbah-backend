<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrasi pelebar sebelumnya (2026_06_30_140000) hanya menjalankan ALTER untuk MySQL/MariaDB;
 * di Postgres cabang else-nya kosong sehingga CHECK constraint enum lama
 * (`not_ready`,`preparing`,`ready`,`failed`) masih berlaku dan MENOLAK nilai `self_design_required`
 * yang dipakai baik Shopee maupun Lazada (order SOF/DBS). Migrasi ini membuang constraint tersebut
 * di Postgres agar nilai status label baru diperbolehkan. Non-destruktif.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'shipping_label_status')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_shipping_label_status_check');
        } elseif ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE sales_orders MODIFY shipping_label_status VARCHAR(32) NULL');
        }
    }

    public function down(): void
    {
        // Non-destruktif: sengaja tidak memasang ulang CHECK constraint karena dapat
        // memblokir nilai status yang sah (self_design_required).
    }
};
