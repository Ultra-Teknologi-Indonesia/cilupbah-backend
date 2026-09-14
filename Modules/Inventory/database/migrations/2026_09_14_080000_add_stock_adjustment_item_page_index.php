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
            'idx_stock_adj_items_doc_created_id',
            'stock_adjustment_items',
            ['stock_adjustment_id', 'created_at', 'id'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_stock_adj_items_doc_created_id\n             ON stock_adjustment_items (stock_adjustment_id, created_at DESC, id DESC)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_stock_adj_items_doc_created_id');
    }
};
