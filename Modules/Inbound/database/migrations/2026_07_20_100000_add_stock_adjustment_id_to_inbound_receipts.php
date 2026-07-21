<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->uuid('stock_adjustment_id')->nullable()->after('condition');

            $table->foreign('stock_adjustment_id')
                ->references('id')
                ->on('stock_adjustments')
                ->nullOnDelete();

            $table->index('stock_adjustment_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbound_receipts', function (Blueprint $table) {
            $table->dropForeign(['stock_adjustment_id']);
            $table->dropIndex(['stock_adjustment_id']);
            $table->dropColumn('stock_adjustment_id');
        });
    }
};
