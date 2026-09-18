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
            'idx_failed_jobs_queue_failed_at',
            'failed_jobs',
            ['queue', 'failed_at'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_failed_jobs_queue_failed_at ON failed_jobs (queue, failed_at DESC)'
        );

        ConcurrentIndex::create(
            'idx_failed_jobs_payload_trgm',
            'failed_jobs',
            ['payload'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_failed_jobs_payload_trgm ON failed_jobs USING gin (payload gin_trgm_ops)'
        );

        ConcurrentIndex::create(
            'idx_failed_jobs_exception_trgm',
            'failed_jobs',
            ['exception'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_failed_jobs_exception_trgm ON failed_jobs USING gin (exception gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_failed_jobs_queue_failed_at');
        ConcurrentIndex::drop('idx_failed_jobs_payload_trgm');
        ConcurrentIndex::drop('idx_failed_jobs_exception_trgm');
    }
};
