<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_channel_stock_outbox_dispatch '
                .'ON channel_stock_sync_outbox (status, queue_tier, next_attempt_at, updated_at)'
            );
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_channel_stock_outbox_lease '
                .'ON channel_stock_sync_outbox (status, lease_expires_at, channel_shop_id)'
            );

            return;
        }

        Schema::table('channel_stock_sync_outbox', function (Blueprint $table): void {
            $table->index(
                ['status', 'queue_tier', 'next_attempt_at', 'updated_at'],
                'idx_channel_stock_outbox_dispatch',
            );
            $table->index(
                ['status', 'lease_expires_at', 'channel_shop_id'],
                'idx_channel_stock_outbox_lease',
            );
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_channel_stock_outbox_dispatch');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_channel_stock_outbox_lease');

            return;
        }

        Schema::table('channel_stock_sync_outbox', function (Blueprint $table): void {
            $table->dropIndex('idx_channel_stock_outbox_dispatch');
            $table->dropIndex('idx_channel_stock_outbox_lease');
        });
    }
};
