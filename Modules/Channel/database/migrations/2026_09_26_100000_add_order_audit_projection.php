<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    private const WEBHOOK_INDEX = 'idx_webhook_order_audit_identity_received';

    private const SALES_ORDER_INDEX = 'idx_sales_orders_order_audit_identity_date';

    public function up(): void
    {
        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            if (! Schema::hasColumn('channel_webhook_inbox', 'order_reference')) {
                $table->string('order_reference', 128)->nullable();
            }
            if (! Schema::hasColumn('channel_webhook_inbox', 'marketplace_status')) {
                $table->string('marketplace_status', 128)->nullable();
            }
        });

        $this->backfillWebhookProjection();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::WEBHOOK_INDEX
                .' ON channel_webhook_inbox (channel, shop_id, order_reference, received_at DESC) '
                .'WHERE order_reference IS NOT NULL'
            );
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::SALES_ORDER_INDEX
                .' ON sales_orders (source, channel_shop_id, channel_order_no, transaction_date DESC) '
                .'WHERE channel_order_no IS NOT NULL AND channel_order_no <> \'\''
            );

            return;
        }

        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->index(
                ['channel', 'shop_id', 'order_reference', 'received_at'],
                self::WEBHOOK_INDEX,
            );
        });
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->index(
                ['source', 'channel_shop_id', 'channel_order_no', 'transaction_date'],
                self::SALES_ORDER_INDEX,
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::WEBHOOK_INDEX);
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::SALES_ORDER_INDEX);
        } else {
            Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
                $table->dropIndex(self::WEBHOOK_INDEX);
            });
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->dropIndex(self::SALES_ORDER_INDEX);
            });
        }

        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->dropColumn(['order_reference', 'marketplace_status']);
        });
    }

    private function backfillWebhookProjection(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE channel_webhook_inbox
            SET
                order_reference = CASE LOWER(channel)
                    WHEN 'shopee' THEN COALESCE(payload->'data'->>'ordersn', payload->'data'->>'order_sn', payload->>'ordersn', payload->>'order_sn')
                    WHEN 'tiktok' THEN COALESCE(payload->'data'->>'order_id', payload->'data'->>'main_order_id', payload->>'order_id', payload->>'main_order_id')
                    WHEN 'lazada' THEN COALESCE(payload->'data'->>'trade_order_id', payload->'data'->>'order_id', payload->>'trade_order_id', payload->>'order_id')
                    WHEN 'woocommerce' THEN COALESCE(payload->>'id', payload->>'order_id')
                    ELSE NULL
                END,
                marketplace_status = CASE LOWER(channel)
                    WHEN 'shopee' THEN COALESCE(payload->'data'->>'status', payload->'data'->>'order_status', payload->>'status')
                    WHEN 'tiktok' THEN COALESCE(payload->'data'->>'order_status', payload->'data'->>'status', payload->>'order_status', payload->>'status')
                    WHEN 'lazada' THEN COALESCE(payload->'data'->>'order_status', payload->'data'->>'status', payload->>'order_status', payload->>'status')
                    WHEN 'woocommerce' THEN payload->>'status'
                    ELSE NULL
                END
            WHERE order_reference IS NULL OR marketplace_status IS NULL;
        SQL);
    }
};
