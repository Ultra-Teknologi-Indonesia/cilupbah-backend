<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'sales_orders_status_check';

    private const STATUS_VALUES = [
        'pending',
        'reserved',
        'picked',
        'packed',
        'shipped',
        'returned',
        'cancelled',
        'UNPAID',
        'READY',
        'AWAITING_BUYER_CONFIRMATION',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'status')) {
            return;
        }

        $this->replaceConstraint(self::STATUS_VALUES);
    }

    public function down(): void
    {
        if (! Schema::hasTable('sales_orders') || ! Schema::hasColumn('sales_orders', 'status')) {
            return;
        }

        $this->replaceConstraint(array_values(array_diff(self::STATUS_VALUES, ['returned'])));
    }

    private function replaceConstraint(array $statuses): void
    {
        $list = collect($statuses)
            ->map(fn (string $status): string => "'".str_replace("'", "''", $status)."'")
            ->implode(', ');

        DB::statement('ALTER TABLE sales_orders DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement(
            'ALTER TABLE sales_orders ADD CONSTRAINT '.self::CONSTRAINT.' '
            ."CHECK (status IS NULL OR status IN ({$list})) NOT VALID",
        );
    }
};
