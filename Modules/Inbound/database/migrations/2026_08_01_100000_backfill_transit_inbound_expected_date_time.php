<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('inbounds')
            ->where('type', 'TRANSIT_IN')
            ->where('source_type', 'transfer')
            ->whereNotNull('expected_date')
            ->whereRaw("expected_date::time = '00:00:00'")
            ->update(['expected_date' => DB::raw('created_at')]);
    }

    public function down(): void
    {
    }
};
