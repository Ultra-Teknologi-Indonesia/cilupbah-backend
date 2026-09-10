<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->string('channel_return_id')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_return_reference '
                .'ON channel_webhook_inbox (channel, shop_id, channel_return_id, received_at DESC) '
                .'WHERE channel_return_id IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_received_at_status '
                .'ON channel_webhook_inbox (received_at) '
                ."WHERE status = 'RECEIVED'"
            );
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_failed_created_at '
                .'ON channel_webhook_inbox (created_at) '
                ."WHERE status = 'FAILED'"
            );
        } else {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->index(
                    ['channel', 'shop_id', 'channel_return_id', 'received_at'],
                    'idx_webhook_inbox_return_reference',
                );
                $table->index(['received_at', 'status'], 'idx_webhook_inbox_received_at_status');
                $table->index(['created_at', 'status'], 'idx_webhook_inbox_failed_created_at');
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_webhook_inbox_return_reference');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_webhook_inbox_received_at_status');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_webhook_inbox_failed_created_at');
        } else {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->dropIndex('idx_webhook_inbox_return_reference');
                $table->dropIndex('idx_webhook_inbox_received_at_status');
                $table->dropIndex('idx_webhook_inbox_failed_created_at');
            });
        }

        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->dropColumn('channel_return_id');
        });
    }
};
