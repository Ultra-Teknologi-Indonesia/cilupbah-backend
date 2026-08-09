<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_source_created_at ON sales_orders (source, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_is_paid ON sales_orders (is_paid)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_received_date ON sales_orders (received_date)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_channel_cancel_status ON sales_orders (channel_cancel_status)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_channel_shop_id ON sales_orders (channel_shop_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_shipping_provider ON sales_orders (shipping_provider)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_source_created_at');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_is_paid');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_received_date');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_channel_cancel_status');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_channel_shop_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_shipping_provider');
    }
};
