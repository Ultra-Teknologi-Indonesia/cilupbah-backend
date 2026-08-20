<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_picklists_status_completed ON picklists (status, completed_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_packlists_status_completed ON packlists (status, completed_at DESC)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_picklist_items_order_picklist ON picklist_items (order_id, picklist_id)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_packlist_items_order_item_id ON packlist_items (order_item_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_picklists_status_completed');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_packlists_status_completed');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_picklist_items_order_picklist');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_packlist_items_order_item_id');
    }
};
