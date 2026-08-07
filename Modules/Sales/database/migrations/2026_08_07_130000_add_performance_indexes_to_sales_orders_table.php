<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index performa untuk halaman daftar pesanan (jalur terpadat):
 * - created_at        : default sort (-created_at) di tab "semua"
 * - transaction_date  : filter rentang tanggal (whereDateFrom/whereDateTo)
 * - (status,created_at): daftar per-tab (status) yang tetap terurut created_at
 *
 * Dibangun CONCURRENTLY agar TIDAK mengunci tulis pada tabel besar saat deploy.
 * Karena itu migrasi ini berjalan di luar transaksi.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_created_at ON sales_orders (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_transaction_date ON sales_orders (transaction_date)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_status_created_at ON sales_orders (status, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_created_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_transaction_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_status_created_at');
    }
};
