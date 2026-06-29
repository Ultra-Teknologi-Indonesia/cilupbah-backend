<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('inventories', function (Blueprint $table) {
            if (! Schema::hasColumn('inventories', 'on_order')) {
                $table->integer('on_order')->default(0)->after('on_hand');
            }
        });

        DB::statement('UPDATE inventories SET on_order = 0, reserved = 0, available = on_hand');

        Schema::table('inventories', function (Blueprint $table) {
            if (! $this->hasIndex('inventories', 'inventories_item_loc_avail_idx')) {
                $table->index(['item_id', 'location_id'], 'inventories_item_loc_avail_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            if ($this->hasIndex('inventories', 'inventories_item_loc_avail_idx')) {
                $table->dropIndex('inventories_item_loc_avail_idx');
            }
        });

    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        );
        return count($indexes) > 0;
    }
};
