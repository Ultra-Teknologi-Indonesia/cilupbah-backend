<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    }
};
