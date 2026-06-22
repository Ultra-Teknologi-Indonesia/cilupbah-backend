<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE inventory_transfer_status AS ENUM ('DRAFT', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'CANCELLED')");

        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status DROP DEFAULT");
        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status TYPE inventory_transfer_status USING status::inventory_transfer_status");
        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status SET DEFAULT 'DRAFT'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status DROP DEFAULT");
        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status TYPE varchar(30) USING status::text");
        DB::statement("ALTER TABLE inventory_transfers ALTER COLUMN status SET DEFAULT 'DRAFT'");

        DB::statement("DROP TYPE IF EXISTS inventory_transfer_status");
    }
};
