<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL cannot create or drop an index concurrently in a transaction.
     */
    public $withinTransaction = false;

    private const INDEX = 'uq_sales_orders_channel_identity';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS uq_sales_orders_channel_identity
                ON sales_orders (source, channel_shop_id, channel_order_no)
                WHERE source IS NOT NULL
                  AND channel_shop_id IS NOT NULL
                  AND channel_order_no IS NOT NULL
                  AND channel_order_no <> ''
            SQL);

            return;
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->unique(
                ['source', 'channel_shop_id', 'channel_order_no'],
                self::INDEX,
            );
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);

            return;
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
        });
    }
};
