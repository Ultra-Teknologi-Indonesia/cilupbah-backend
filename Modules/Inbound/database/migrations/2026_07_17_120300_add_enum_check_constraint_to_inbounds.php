<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::selectOne("
            SELECT 1 FROM pg_constraint
            WHERE conname = 'inbounds_status_check'
              AND conrelid = 'inbounds'::regclass
        ");

        if ($exists) {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE inbounds
            ADD CONSTRAINT inbounds_status_check
            CHECK (status IN (
                'DRAFT','PARTIAL','RECEIVED','PUTAWAY_IN_PROGRESS','COMPLETED','CANCELLED'
            ))
            NOT VALID
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inbounds DROP CONSTRAINT IF EXISTS inbounds_status_check');
    }
};
