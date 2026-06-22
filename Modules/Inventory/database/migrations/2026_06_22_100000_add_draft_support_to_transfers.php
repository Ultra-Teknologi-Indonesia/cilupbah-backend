<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE inventory_transfers ALTER COLUMN source_location_id DROP NOT NULL');
        DB::statement('ALTER TABLE inventory_transfers ALTER COLUMN destination_location_id DROP NOT NULL');

        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->string('sync_status', 20)->default('SYNCED')->after('serial_no');
            $table->text('sync_error')->nullable()->after('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfer_items', function (Blueprint $table) {
            $table->dropColumn(['sync_status', 'sync_error']);
        });

        DB::statement('ALTER TABLE inventory_transfers ALTER COLUMN source_location_id SET NOT NULL');
        DB::statement('ALTER TABLE inventory_transfers ALTER COLUMN destination_location_id SET NOT NULL');
    }
};
