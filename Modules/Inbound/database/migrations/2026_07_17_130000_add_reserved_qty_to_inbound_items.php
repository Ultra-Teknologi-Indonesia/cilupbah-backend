<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->integer('reserved_qty')->default(0)->after('putaway_qty');
            $table->index('reserved_qty');
        });

        DB::statement("
            UPDATE inbound_items ii
            SET reserved_qty = COALESCE(sub.total_reserved, 0)
            FROM (
                SELECT p.source_id AS inbound_id, pi.item_id,
                       SUM(GREATEST(pi.qty - pi.putaway_qty, 0)) AS total_reserved
                FROM putaway_items pi
                JOIN putaways p ON p.id = pi.putaway_id
                WHERE p.source_type = 'INBOUND'
                  AND p.source_id IS NOT NULL
                  AND p.status IN ('NOT_STARTED', 'IN_PROGRESS')
                GROUP BY p.source_id, pi.item_id
            ) sub
            WHERE ii.inbound_id = sub.inbound_id
              AND ii.item_id = sub.item_id
        ");

        DB::statement("
            UPDATE inbound_items ii
            SET reserved_qty = ii.reserved_qty + COALESCE(sub.pivot_reserved, 0)
            FROM (
                SELECT pis.inbound_item_id,
                       SUM(GREATEST(pis.qty - COALESCE(pis.putaway_qty, 0), 0)) AS pivot_reserved
                FROM putaway_item_sources pis
                JOIN putaway_items pi ON pi.id = pis.putaway_item_id
                JOIN putaways p ON p.id = pi.putaway_id
                WHERE p.source_type = 'INBOUND'
                  AND p.source_id IS NULL
                  AND p.status IN ('NOT_STARTED', 'IN_PROGRESS')
                GROUP BY pis.inbound_item_id
            ) sub
            WHERE ii.id = sub.inbound_item_id
        ");
    }

    public function down(): void
    {
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropIndex(['reserved_qty']);
            $table->dropColumn('reserved_qty');
        });
    }
};
