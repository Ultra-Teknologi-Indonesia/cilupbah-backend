<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->string('source_type')->nullable()->after('type');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('inbound_items', function (Blueprint $table) {
            $table->integer('putaway_qty')->default(0)->after('received_qty');
            $table->integer('discrepancy_qty')->default(0)->after('putaway_qty');
            $table->text('discrepancy_note')->nullable()->after('discrepancy_qty');
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->string('condition')->default('GOOD')->after('serial_no');
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropColumn(['putaway_qty', 'discrepancy_qty', 'discrepancy_note']);
        });

        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropColumn('condition');
        });
    }
};
