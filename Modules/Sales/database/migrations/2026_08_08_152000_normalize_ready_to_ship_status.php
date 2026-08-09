<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CANONICAL = [
        'pending', 'reserved', 'picked', 'packed', 'shipped',
        'cancelled', 'UNPAID', 'READY', 'AWAITING_BUYER_CONFIRMATION',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'status')) {
            return;
        }

        DB::statement("UPDATE sales_orders SET status = 'packed' WHERE status = 'ready-to-ship'");

        $list = collect(self::CANONICAL)
            ->map(fn ($v) => "'" . str_replace("'", "''", $v) . "'")
            ->implode(', ');

        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_status_check');
        DB::statement(
            "ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check " .
            "CHECK (status IS NULL OR status IN ({$list})) NOT VALID"
        );
    }

    public function down(): void
    {

        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'status')) {
            return;
        }

        $list = collect(array_merge(self::CANONICAL, ['ready-to-ship']))
            ->map(fn ($v) => "'" . str_replace("'", "''", $v) . "'")
            ->implode(', ');

        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS sales_orders_status_check');
        DB::statement(
            "ALTER TABLE sales_orders ADD CONSTRAINT sales_orders_status_check " .
            "CHECK (status IS NULL OR status IN ({$list})) NOT VALID"
        );
    }
};
