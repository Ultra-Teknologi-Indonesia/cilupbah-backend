<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('picklist_item_allocations', function (Blueprint $table): void {
            $table->unsignedInteger('physical_committed_qty')
                ->default(0)
                ->after('qty');
            $table->index(['picklist_item_id', 'physical_committed_qty'], 'pia_picklist_physical_idx');
        });

        DB::table('picklist_item_allocations')
            ->whereNotNull('movement_id')
            ->update(['physical_committed_qty' => DB::raw('qty')]);
    }

    public function down(): void
    {
        Schema::table('picklist_item_allocations', function (Blueprint $table): void {
            $table->dropIndex('pia_picklist_physical_idx');
            $table->dropColumn('physical_committed_qty');
        });
    }
};
