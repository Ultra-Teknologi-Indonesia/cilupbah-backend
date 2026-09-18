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
            'idx_webhook_inbox_payload_trgm',
            'channel_webhook_inbox',
            ['payload'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_payload_trgm ON channel_webhook_inbox USING gin ((payload::text) gin_trgm_ops)'
        );

        ConcurrentIndex::create(
            'idx_webhook_inbox_channel_shop_received',
            'channel_webhook_inbox',
            ['channel', 'shop_id', 'received_at'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_channel_shop_received ON channel_webhook_inbox (channel, shop_id, received_at DESC)'
        );

        ConcurrentIndex::create(
            'idx_webhook_inbox_status_received',
            'channel_webhook_inbox',
            ['status', 'received_at'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_status_received ON channel_webhook_inbox (status, received_at DESC)'
        );

        ConcurrentIndex::create(
            'idx_webhook_inbox_error_trgm',
            'channel_webhook_inbox',
            ['error'],
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_webhook_inbox_error_trgm ON channel_webhook_inbox USING gin (error gin_trgm_ops) WHERE error IS NOT NULL'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        ConcurrentIndex::drop('idx_webhook_inbox_payload_trgm');
        ConcurrentIndex::drop('idx_webhook_inbox_channel_shop_received');
        ConcurrentIndex::drop('idx_webhook_inbox_status_received');
        ConcurrentIndex::drop('idx_webhook_inbox_error_trgm');
    }
};
