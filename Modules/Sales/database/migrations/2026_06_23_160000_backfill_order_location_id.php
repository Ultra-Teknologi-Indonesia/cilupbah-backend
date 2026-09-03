<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'location_id')) {
            return;
        }

        $defaultLocation = DB::table('locations')->where('is_warehouse', true)->where('is_active', true)->first();

        if (! $defaultLocation) {
            return;
        }

        DB::table('sales_orders')
            ->whereNull('location_id')
            ->update(['location_id' => $defaultLocation->id]);
    }

    public function down(): void {}
};
