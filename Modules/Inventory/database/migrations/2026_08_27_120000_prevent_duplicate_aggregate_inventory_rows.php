<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('inventories')
            ->whereNull('bin_id')
            ->select('item_id', 'location_id', 'batch_no', 'serial_no')
            ->groupBy('item_id', 'location_id', 'batch_no', 'serial_no')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new \RuntimeException(
                'Tidak dapat membuat unique aggregate inventory index: masih ada duplicate row dengan bin_id NULL.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS inventories_unique_aggregate_identifier '
            .'ON inventories (item_id, location_id, batch_no, serial_no) '
            .'WHERE bin_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS inventories_unique_aggregate_identifier');
    }
};
