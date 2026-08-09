<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
