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

        // PostgreSQL stores these columns as boolean. Laravel normalises
        // boolean bindings to integers (1/0), which PostgreSQL rejects when
        // comparing against a boolean column. Use typed predicates here so a
        // fresh PostgreSQL test database can run all migrations as well.
        $defaultLocation = DB::table('locations')
            ->whereRaw('"is_warehouse" IS TRUE')
            ->whereRaw('"is_active" IS TRUE')
            ->first();

        if (! $defaultLocation) {
            return;
        }

        DB::table('sales_orders')
            ->whereNull('location_id')
            ->update(['location_id' => $defaultLocation->id]);
    }

    public function down(): void {}
};
