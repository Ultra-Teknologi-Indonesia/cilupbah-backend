<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ACTIONS = "'CREATED', 'PAID', 'PROCESS', 'PICK_STARTED', 'PICK_FAILED', 'FINISH_PICK', 'PACK_STARTED', 'FINISH_PACK', 'LABEL_PRINTED', 'READY_TO_SHIP', 'DRIVER_CALLED', 'TRACKING_UPDATED', 'CHANNEL_STATUS', 'RECEIVED_BY_BUYER', 'RETURN_DECISION', 'FIELD_CHANGED', 'SHIPPED', 'COMPLETED', 'CANCELLED', 'ZONE_ASSIGNED', 'ITEM_CREATED', 'ADDED_TO_SHIPMENT', 'REMOVED_FROM_SHIPMENT', 'SHIPMENT_HANDED_OVER'";

    public function up(): void
    {
        DB::statement('ALTER TABLE sales_order_status_histories DROP CONSTRAINT IF EXISTS so_hist_action_check');
        DB::statement('ALTER TABLE sales_order_status_histories ADD CONSTRAINT so_hist_action_check CHECK (action IN ('.self::ACTIONS.'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sales_order_status_histories DROP CONSTRAINT IF EXISTS so_hist_action_check');
        DB::statement("ALTER TABLE sales_order_status_histories ADD CONSTRAINT so_hist_action_check CHECK (action IN ('CREATED', 'PAID', 'PROCESS', 'PICK_STARTED', 'PICK_FAILED', 'FINISH_PICK', 'PACK_STARTED', 'FINISH_PACK', 'LABEL_PRINTED', 'READY_TO_SHIP', 'DRIVER_CALLED', 'TRACKING_UPDATED', 'CHANNEL_STATUS', 'RECEIVED_BY_BUYER', 'RETURN_DECISION', 'FIELD_CHANGED', 'SHIPPED', 'COMPLETED', 'CANCELLED', 'ZONE_ASSIGNED', 'ITEM_CREATED'))");
    }
};
