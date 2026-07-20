<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LazadaToInternalOrderMapper dulu memetakan status Lazada ke kosakata TikTok
 * (AWAITING_SHIPMENT, AWAITING_COLLECTION), yang tidak dikenali
 * ChannelStatusNormalizer sehingga channel_status jatuh ke UNKNOWN dan pesanan
 * mandek. Peta sudah diperbaiki; migrasi ini membereskan baris yang terlanjur.
 *
 * Status mentah Lazada tidak pernah disimpan pada baris lama (mapper belum
 * mengisi channel_fulfillment_status), jadi pemulihan bersandar pada status
 * internal yang sudah terbentuk — bukan menebak status channel dari nol.
 */
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
        // Tidak dibalik: nilai UNKNOWN sebelumnya tidak membawa informasi apa pun.
    }
};
