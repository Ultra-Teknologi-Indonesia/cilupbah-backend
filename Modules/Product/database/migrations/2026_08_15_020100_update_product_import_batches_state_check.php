<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_import_batches DROP CONSTRAINT IF EXISTS product_import_batches_state_check');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE product_import_batches ADD CONSTRAINT product_import_batches_state_check CHECK (state::text = ANY (ARRAY['queued'::character varying, 'processing'::character varying, 'done'::character varying, 'done_with_errors'::character varying, 'failed'::character varying]::text[]))");
        }
    }
};
