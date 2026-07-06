<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Warehouse\Models\Location;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->string('stock_source_mode', 16)->default('location')->after('order_sync_enabled');
            $table->foreignUuid('stock_source_location_id')->nullable()->after('stock_source_mode')
                ->constrained('locations')->nullOnDelete();
        });

        $kecilId = DB::table('locations')->where('location_code', Location::SYSTEM_KECIL_CODE)->value('id');

        if ($kecilId) {
            DB::table('channel_shops')->update([
                'stock_source_mode' => 'location',
                'stock_source_location_id' => $kecilId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_source_location_id');
            $table->dropColumn('stock_source_mode');
        });
    }
};
