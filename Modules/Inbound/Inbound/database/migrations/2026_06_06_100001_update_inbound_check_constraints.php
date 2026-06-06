<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inbounds DROP CONSTRAINT IF EXISTS inbounds_status_check');
        DB::statement("ALTER TABLE inbounds ADD CONSTRAINT inbounds_status_check CHECK (status IN ('DRAFT','PARTIAL','RECEIVED','PUTAWAY_IN_PROGRESS','COMPLETED','CANCELLED'))");

        DB::statement('ALTER TABLE inbounds DROP CONSTRAINT IF EXISTS inbounds_type_check');
        DB::statement("ALTER TABLE inbounds ADD CONSTRAINT inbounds_type_check CHECK (type IN ('PURCHASE_ORDER','SALES_RETURN','TRANSIT_IN','CONSIGNMENT'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE inbounds DROP CONSTRAINT IF EXISTS inbounds_status_check');
        DB::statement("ALTER TABLE inbounds ADD CONSTRAINT inbounds_status_check CHECK (status IN ('DRAFT','PARTIAL','COMPLETED','CANCELLED'))");

        DB::statement('ALTER TABLE inbounds DROP CONSTRAINT IF EXISTS inbounds_type_check');
        DB::statement("ALTER TABLE inbounds ADD CONSTRAINT inbounds_type_check CHECK (type IN ('PURCHASE_ORDER','SALES_RETURN','TRANSIT_IN'))");
    }
};
