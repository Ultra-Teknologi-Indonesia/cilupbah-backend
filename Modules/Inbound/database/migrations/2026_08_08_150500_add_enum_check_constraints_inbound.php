<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    private array $checks = [
        ['inbound_assignments', 'status', ['PENDING', 'IN_PROGRESS', 'COMPLETED']],
        ['inbound_participants', 'status', ['ACTIVE', 'DONE', 'WITHDRAWN']],
        ['inbound_participants', 'role', ['RECEIVER']],
        ['inbound_items', 'condition', ['GOOD', 'PARTIAL']],
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
