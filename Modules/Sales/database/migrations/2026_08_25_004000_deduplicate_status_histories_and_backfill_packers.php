<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement("
            DELETE FROM sales_order_status_histories s1
            WHERE s1.action IN ('FINISH_PACK', 'FINISH_PICK')
              AND (s1.actor_id IS NULL OR LOWER(COALESCE(s1.actor_name, '')) = 'system' OR LOWER(COALESCE(s1.actor_email, '')) = 'system')
              AND EXISTS (
                  SELECT 1 FROM sales_order_status_histories s2
                  WHERE s2.salesorder_id = s1.salesorder_id
                    AND s2.action = s1.action
                    AND s2.id != s1.id
                    AND s2.actor_id IS NOT NULL
                    AND LOWER(COALESCE(s2.actor_name, '')) != 'system'
                    AND LOWER(COALESCE(s2.actor_email, '')) != 'system'
                    AND ABS(EXTRACT(EPOCH FROM (s2.created_at - s1.created_at))) <= 60
              )
        ");

        DB::statement("
            UPDATE sales_order_status_histories h
            SET actor_id = u.id,
                actor_name = u.name,
                actor_email = u.email
            FROM packlists p
            JOIN users u ON u.id = p.packer_id
            WHERE h.salesorder_id = p.order_id
              AND h.action = 'FINISH_PACK'
              AND (h.actor_id IS NULL OR LOWER(COALESCE(h.actor_name, '')) = 'system' OR LOWER(COALESCE(h.actor_email, '')) = 'system')
              AND p.packer_id IS NOT NULL
        ");

        DB::statement("
            UPDATE sales_order_status_histories h
            SET actor_id = u.id,
                actor_name = u.name,
                actor_email = u.email
            FROM picklist_items pi
            JOIN picklists p ON p.id = pi.picklist_id
            JOIN sales_order_items soi ON soi.id = pi.order_item_id
            JOIN users u ON u.id = p.picker_id
            WHERE h.salesorder_id = soi.order_id
              AND h.action = 'FINISH_PICK'
              AND (h.actor_id IS NULL OR LOWER(COALESCE(h.actor_name, '')) = 'system' OR LOWER(COALESCE(h.actor_email, '')) = 'system')
              AND p.picker_id IS NOT NULL
        ");
    }

    public function down(): void
    {

    }
};
