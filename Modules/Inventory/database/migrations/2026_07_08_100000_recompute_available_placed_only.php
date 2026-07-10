<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement(<<<'SQL'
            UPDATE inventories AS i
            SET available = GREATEST(0, i.on_hand - i.reserved)
            FROM location_bins AS b
            WHERE b.id = i.bin_id
              AND b.is_inbound = false
        SQL);

        DB::statement(<<<'SQL'
            UPDATE inventories AS i
            SET available = 0
            WHERE i.bin_id IS NULL
               OR EXISTS (
                   SELECT 1 FROM location_bins AS b
                   WHERE b.id = i.bin_id AND b.is_inbound = true
               )
        SQL);
    }

    public function down(): void
    {

        DB::statement('UPDATE inventories SET available = GREATEST(0, on_hand - reserved)');
    }
};
