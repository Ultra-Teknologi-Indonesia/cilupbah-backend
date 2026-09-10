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
        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->uuid('order_pull_lease_token')->nullable();
            $table->timestamp('order_pull_locked_until')->nullable();
            $table->index('order_pull_locked_until', 'idx_channel_shops_order_pull_locked_until');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_replay_due '
                ."ON channel_webhook_inbox (next_attempt_at, received_at) WHERE status = 'RECEIVED'"
            );

            return;
        }

        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->index(['status', 'next_attempt_at', 'received_at'], 'idx_webhook_inbox_replay_due');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_webhook_inbox_replay_due');
        } else {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->dropIndex('idx_webhook_inbox_replay_due');
            });
        }

        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->dropIndex('idx_channel_shops_order_pull_locked_until');
            $table->dropColumn(['order_pull_lease_token', 'order_pull_locked_until']);
        });
    }
};
