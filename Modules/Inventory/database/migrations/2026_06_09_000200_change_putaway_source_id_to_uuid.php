<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE putaways ALTER COLUMN source_id TYPE UUID USING LPAD(source_id::text, 32, '0')::uuid");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE putaways ALTER COLUMN source_id TYPE VARCHAR(32) USING source_id::text");
        }
    }
};
