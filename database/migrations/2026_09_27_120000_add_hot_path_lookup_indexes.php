<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Concurrent index creation must not run inside Laravel's migration
     * transaction. This keeps order, label, and product-sync writes flowing
     * while the indexes are built on production.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->createPortableIndexes();

            return;
        }

        $indexes = [
            [
                'name' => 'idx_bulk_label_batches_user_status_created',
                'table' => 'bulk_shipping_label_batches',
                'columns' => ['user_id', 'status', 'created_at'],
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_bulk_label_batches_user_status_created '
                    .'ON bulk_shipping_label_batches (user_id, status, created_at DESC)',
            ],
            [
                'name' => 'idx_product_sync_logs_action_created',
                'table' => 'product_sync_logs',
                'columns' => ['action', 'created_at'],
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_product_sync_logs_action_created '
                    .'ON product_sync_logs (action, created_at DESC)',
            ],
            [
                'name' => 'idx_product_sync_logs_shop_created',
                'table' => 'product_sync_logs',
                'columns' => ['channel_shop_id', 'created_at'],
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_product_sync_logs_shop_created '
                    .'ON product_sync_logs (channel_shop_id, created_at DESC)',
            ],
            [
                'name' => 'idx_product_sync_logs_upload_failed_created',
                'table' => 'product_sync_logs',
                'columns' => ['action', 'status', 'created_at'],
                'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_product_sync_logs_upload_failed_created '
                    .'ON product_sync_logs (created_at DESC) '
                    ."WHERE action = 'upload' AND status = 'failed'",
            ],
        ];

        foreach ($indexes as $index) {
            if (! $this->tableHasColumns($index['table'], $index['columns'])) {
                continue;
            }

            DB::statement($index['sql']);

            $ready = DB::selectOne(
                'SELECT i.indisvalid AND i.indisready AS ready
                 FROM pg_class c
                 JOIN pg_index i ON i.indexrelid = c.oid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = current_schema() AND c.relname = ?',
                [$index['name']],
            );

            if (! $ready?->ready) {
                throw new RuntimeException("Index {$index['name']} belum valid setelah dibuat.");
            }
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            foreach ([
                'idx_bulk_label_batches_user_status_created',
                'idx_product_sync_logs_action_created',
                'idx_product_sync_logs_shop_created',
                'idx_product_sync_logs_upload_failed_created',
            ] as $index) {
                DB::statement("DROP INDEX IF EXISTS {$index}");
            }

            return;
        }

        foreach ([
            'idx_bulk_label_batches_user_status_created',
            'idx_product_sync_logs_action_created',
            'idx_product_sync_logs_shop_created',
            'idx_product_sync_logs_upload_failed_created',
        ] as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }

    /**
     * SQLite/MySQL test environments do not support PostgreSQL's partial
     * index syntax. Keep the migration usable there with the portable
     * equivalents; production uses the concurrent PostgreSQL path above.
     */
    private function createPortableIndexes(): void
    {
        if (Schema::hasTable('bulk_shipping_label_batches')
            && Schema::hasColumn('bulk_shipping_label_batches', 'user_id')
            && Schema::hasColumn('bulk_shipping_label_batches', 'status')
            && Schema::hasColumn('bulk_shipping_label_batches', 'created_at')) {
            Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
                $table->index(
                    ['user_id', 'status', 'created_at'],
                    'idx_bulk_label_batches_user_status_created',
                );
            });
        }

        if (Schema::hasTable('product_sync_logs')) {
            Schema::table('product_sync_logs', function (Blueprint $table): void {
                $table->index(
                    ['action', 'created_at'],
                    'idx_product_sync_logs_action_created',
                );
                $table->index(
                    ['channel_shop_id', 'created_at'],
                    'idx_product_sync_logs_shop_created',
                );
            });
        }
    }

    private function tableHasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }
};
