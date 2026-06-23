<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaultLocation = DB::table('locations')->where('is_warehouse', true)->where('is_active', true)->first();

        if (! $defaultLocation) {
            return;
        }

        DB::table('sales_orders')
            ->whereNull('location_id')
            ->update(['location_id' => $defaultLocation->id]);
    }

    public function down(): void
    {
        // Irreversible — cannot distinguish which were originally null.
    }
};
