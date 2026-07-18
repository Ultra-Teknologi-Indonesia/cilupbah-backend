<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inventories', 'reserved')) {
            return;
        }

        DB::transaction(function () {
            DB::statement('UPDATE inventories SET on_order = reserved');

            DB::statement('UPDATE inventories SET available = 0 WHERE bin_id IS NULL');
            DB::statement("UPDATE inventories SET available = on_hand - on_order WHERE bin_id IN (SELECT id FROM location_bins WHERE is_inbound = false)");
        });

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('reserved');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventories', 'reserved')) {
            return;
        }

        Schema::table('inventories', function (Blueprint $table) {
            $table->integer('reserved')->default(0)->after('on_order');
        });

        DB::statement('UPDATE inventories SET reserved = on_order');
        DB::statement('UPDATE inventories SET on_order = 0');
        DB::statement('UPDATE inventories SET available = 0 WHERE bin_id IS NULL');
        DB::statement("UPDATE inventories SET available = GREATEST(0, on_hand - reserved) WHERE bin_id IN (SELECT id FROM location_bins WHERE is_inbound = false)");
    }
};
