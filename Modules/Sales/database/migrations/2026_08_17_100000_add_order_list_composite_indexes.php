<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        // 1. Composite sort index for default query order (transaction_date DESC, created_at DESC)
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_txn_created ON sales_orders (transaction_date DESC, created_at DESC)');

        // 2. Composite status + sort index for tab queries
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_status_txn_created ON sales_orders (status, transaction_date DESC, created_at DESC)');

        // 3. Partial index for non-shadow orders (default query path across all tabs)
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_so_not_shadow_txn ON sales_orders (status, transaction_date DESC, created_at DESC) WHERE (is_shadow = false OR is_shadow IS NULL)');

        // 4. Index on external_product_id for product_channel_mappings eager loading
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_pcm_external_product_id ON product_channel_mappings (external_product_id)');

        // 5. Index on media (model_type, model_id, collection_name) for Spatie MediaLibrary fast lookups
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_media_model_collection ON media (model_type, model_id, collection_name)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_txn_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_status_txn_created');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_so_not_shadow_txn');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_pcm_external_product_id');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_media_model_collection');
    }
};
