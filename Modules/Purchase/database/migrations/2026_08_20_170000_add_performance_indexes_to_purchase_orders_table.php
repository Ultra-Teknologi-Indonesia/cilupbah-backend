<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_po_created_at ON purchase_orders (created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_po_status_created_at ON purchase_orders (status, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_po_order_date_created ON purchase_orders (order_date DESC, created_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_po_contact_status ON purchase_orders (contact_id, status, created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_po_created_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_po_status_created_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_po_order_date_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_po_contact_status');
    }
};
