<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * pg_trgm backs the `ILIKE '%term%'` half of the `allowedSearch` macro.
 *
 * Without it that half is unindexable, and because it is OR-ed with the
 * full-text half PostgreSQL has to sequential-scan the whole table even when a
 * GIN full-text index exists — a BitmapOr needs both sides to be indexable.
 *
 * pg_trgm is a trusted extension since PostgreSQL 13, so the database owner can
 * install it. Managed instances that forbid it still work: the search keeps
 * functioning, it just falls back to a scan for the substring half.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            Log::warning('pg_trgm extension unavailable; substring search will not be index-backed.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function down(): void
    {
        // Left installed on purpose: dropping it would invalidate trigram
        // indexes created by other migrations.
    }
};
