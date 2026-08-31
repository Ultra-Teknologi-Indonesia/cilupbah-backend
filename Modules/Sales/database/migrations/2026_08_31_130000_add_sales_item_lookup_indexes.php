<?php

use App\Support\ConcurrentIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::create(
            'idx_soi_sku_order_id',
            'sales_order_items',
            ['sku', 'order_id'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_soi_sku_order_id ON sales_order_items (sku, order_id)'
        );

        ConcurrentIndex::create(
            'idx_soi_item_id_order_id',
            'sales_order_items',
            ['item_id', 'order_id'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_soi_item_id_order_id ON sales_order_items (item_id, order_id)'
        );

        ConcurrentIndex::create(
            'idx_inv_movements_source_item_date',
            'inventory_movements',
            ['source', 'item_id', 'transaction_date'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_inv_movements_source_item_date ON inventory_movements (source, item_id, transaction_date DESC)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_soi_sku_order_id');
        ConcurrentIndex::drop('idx_soi_item_id_order_id');
        ConcurrentIndex::drop('idx_inv_movements_source_item_date');
    }
};
