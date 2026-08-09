<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    private array $checks = [
        ['tracking_items', 'status', ['todo', 'in_progress', 'done', 'blocked']],
        ['tracking_items', 'baseline_status', ['todo', 'in_progress', 'done', 'blocked']],
        ['tracking_items', 'priority', ['P0', 'P1', 'P2', 'P3']],
        ['tracking_items', 'method', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
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
