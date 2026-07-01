<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_shipment_type_check");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_shipment_type_check CHECK (shipment_type IN ('REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO', 'INSTANT'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE shipments DROP CONSTRAINT IF EXISTS shipments_shipment_type_check");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_shipment_type_check CHECK (shipment_type IN ('REGULAR', 'EXPRESS', 'SAME_DAY', 'CARGO'))");
    }
};
