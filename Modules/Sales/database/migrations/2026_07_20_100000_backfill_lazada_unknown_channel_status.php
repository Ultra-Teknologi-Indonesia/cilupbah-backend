<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const RECOVERY = [
        'shipped'   => 'SHIPPED',
        'picked'    => 'PROCESSED',
        'packed'    => 'PROCESSED',
        'reserved'  => 'READY_TO_SHIP',
        'pending'   => 'READY_TO_SHIP',
        'cancelled' => 'CANCELLED',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'channel_status')) {
            return;
        }

        foreach (self::RECOVERY as $internal => $channelStatus) {
            DB::table('sales_orders')
                ->where('source', 'lazada')
                ->where('channel_status', 'UNKNOWN')
                ->where('status', $internal)
                ->update(['channel_status' => $channelStatus]);
        }
    }

    public function down(): void
    {

    }
};
