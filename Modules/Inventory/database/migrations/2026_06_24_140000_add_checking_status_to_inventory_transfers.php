<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE inventory_transfer_status ADD VALUE IF NOT EXISTS 'CHECKING' AFTER 'IN_TRANSIT'");
    }

    public function down(): void
    {
        // PostgreSQL does not support removing enum values
    }
};
