<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement("
            UPDATE inbound_items ii
            SET putaway_qty = sub.total_putaway
            FROM (
                SELECT p.source_id AS inbound_id, pi.item_id, SUM(pi.putaway_qty) AS total_putaway
                FROM putaway_items pi
                JOIN putaways p ON p.id = pi.putaway_id
                WHERE p.source_type = 'INBOUND'
                  AND p.source_id IS NOT NULL
                GROUP BY p.source_id, pi.item_id
            ) sub
            WHERE ii.inbound_id = sub.inbound_id
              AND ii.item_id = sub.item_id
              AND ii.putaway_qty < sub.total_putaway
        ");

        DB::statement("
            UPDATE inbounds
            SET status = 'COMPLETED'
            WHERE status IN ('RECEIVED', 'PUTAWAY_IN_PROGRESS')
              AND id IN (
                  SELECT inbound_id
                  FROM inbound_items
                  WHERE received_qty > 0
                  GROUP BY inbound_id
                  HAVING MIN(CASE WHEN putaway_qty >= received_qty THEN 1 ELSE 0 END) = 1
              )
        ");
    }

    public function down(): void
    {

    }
};
