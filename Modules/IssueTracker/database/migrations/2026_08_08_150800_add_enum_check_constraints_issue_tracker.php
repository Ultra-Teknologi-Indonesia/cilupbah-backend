<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    private array $checks = [
        ['issue_tracker_issues', 'status', ['open', 'in_review', 'in_progress', 'resolved', 'closed']],
        ['issue_tracker_issues', 'priority', ['low', 'medium', 'high', 'critical']],
        ['issue_tracker_activities', 'action', ['created', 'commented', 'status_changed', 'assigned']],
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
