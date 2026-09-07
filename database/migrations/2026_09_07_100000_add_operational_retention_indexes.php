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
            'idx_failed_jobs_failed_at',
            'failed_jobs',
            ['failed_at'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_failed_jobs_failed_at ON failed_jobs (failed_at, id)'
        );

        ConcurrentIndex::create(
            'idx_notifications_created_at',
            'notifications',
            ['created_at'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_notifications_created_at ON notifications (created_at, id)'
        );

        ConcurrentIndex::create(
            'idx_webhook_inbox_completed_at',
            'channel_webhook_inbox',
            ['processed_at'],
            "CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_completed_at\n             ON channel_webhook_inbox (processed_at, id)\n             WHERE status IN ('PROCESSED', 'SKIPPED')"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_failed_jobs_failed_at');
        ConcurrentIndex::drop('idx_notifications_created_at');
        ConcurrentIndex::drop('idx_webhook_inbox_completed_at');
    }
};
