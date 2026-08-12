<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ConcurrentIndex
{
    public static function create(string $name, string $table, array $columns, string $sql): void
    {
        if (! Schema::hasTable($table)) {
            Log::info("Skipped index {$name}: table {$table} does not exist.");

            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                Log::info("Skipped index {$name}: {$table}.{$column} does not exist.");

                return;
            }
        }

        self::dropIfInvalid($name);

        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            Log::warning("Skipped index {$name}.", ['error' => $e->getMessage()]);
        }
    }

    public static function drop(string $name): void
    {
        DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
    }

    private static function dropIfInvalid(string $name): void
    {
        $invalid = DB::select(
            'SELECT 1 FROM pg_class c
             JOIN pg_index i ON i.indexrelid = c.oid
             WHERE c.relname = ? AND NOT i.indisvalid',
            [$name]
        );

        if (! empty($invalid)) {
            Log::warning("Dropping invalid index {$name} left behind by an interrupted build.");
            self::drop($name);
        }
    }
}
