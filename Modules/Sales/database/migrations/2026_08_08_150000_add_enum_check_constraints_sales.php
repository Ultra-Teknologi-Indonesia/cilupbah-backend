<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    private array $checks = [

        ['sales_orders', 'status', ['pending', 'reserved', 'picked', 'packed', 'shipped', 'cancelled', 'UNPAID', 'READY', 'AWAITING_BUYER_CONFIRMATION']],
        ['sales_orders', 'channel_cancel_status', ['pending', 'accepted', 'failed']],
        ['sales_orders', 'delivery_method', ['COURIER', 'SELF_PICKUP']],
        ['sales_order_items', 'fulfillment_status', ['PICKED', 'SHORT', 'REJECTED']],
        ['sales_invoices', 'status', ['DRAFT', 'OPEN', 'PAID', 'CANCELLED']],
        ['sales_returns', 'status', ['PENDING', 'ACCEPTED', 'REJECTED', 'COMPLETED', 'CANCELLED']],
        ['sales_returns', 'reason_category', ['FAILED_DELIVERY', 'COMPLAINT', 'CANCEL_SHIPPED', 'REMORSE', 'OTHER']],
        ['sales_return_settlements', 'status', ['DRAFT', 'CONFIRMED', 'COMPLETED']],
        ['sales_settlements', 'status', ['DRAFT', 'CONFIRMED', 'RECONCILED']],
    ];

    public function up(): void
    {
        foreach ($this->checks as [$table, $column, $values]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $constraint = "{$table}_{$column}_check";

            $exists = DB::selectOne(
                'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)',
                [$constraint, $table]
            );
            if ($exists) {
                continue;
            }

            $list = collect($values)
                ->map(fn ($v) => "'" . str_replace("'", "''", $v) . "'")
                ->implode(', ');

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} " .
                "CHECK ({$column} IS NULL OR {$column} IN ({$list})) NOT VALID"
            );
        }
    }

    public function down(): void
    {
        foreach ($this->checks as [$table, $column, $values]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_{$column}_check");
        }
    }
};
