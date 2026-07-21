<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('purchase_orders')
            ->where('status', 'CANCELLED')
            ->update(['status' => 'DRAFT']);

        DB::table('purchase_order_activities')
            ->where('action', 'CANCELLED')
            ->update(['action' => 'STATUS_CHANGED', 'action_id' => '900']);
    }

    public function down(): void
    {

    }
};
