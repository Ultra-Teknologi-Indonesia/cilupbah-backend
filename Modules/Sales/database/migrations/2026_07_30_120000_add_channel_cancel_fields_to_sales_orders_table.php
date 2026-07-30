<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            // Status channel MENTAH (belum dinormalisasi ChannelStatusNormalizer).
            // Wajib untuk pemilihan reason TikTok (UNPAID vs ON_HOLD/AWAITING_SHIPMENT).
            $table->string('channel_status_raw', 50)->nullable()->after('channel_status');

            // Pembatalan yang diajukan seller ke marketplace (BUKAN buyer request).
            // Dipisah dari cancel_requested_at agar tidak memicu AutoAcceptCancelRequestJob.
            $table->timestamp('channel_cancel_requested_at')->nullable()->after('cancel_reject_reason');
            $table->string('channel_cancel_requested_by', 36)->nullable()->after('channel_cancel_requested_at');
            // pending | accepted | rejected | failed
            $table->string('channel_cancel_status', 20)->nullable()->after('channel_cancel_requested_by');
            $table->string('channel_cancel_error', 255)->nullable()->after('channel_cancel_status');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'channel_status_raw',
                'channel_cancel_requested_at',
                'channel_cancel_requested_by',
                'channel_cancel_status',
                'channel_cancel_error',
            ]);
        });
    }
};
