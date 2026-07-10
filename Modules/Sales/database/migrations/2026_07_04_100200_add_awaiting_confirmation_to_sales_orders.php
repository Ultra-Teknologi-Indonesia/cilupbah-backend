<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dateTime('awaiting_confirmation_at')->nullable();
            $table->string('buyer_decision', 24)->nullable();
            $table->dateTime('buyer_decided_at')->nullable();
            $table->uuid('buyer_decided_by')->nullable();

            $table->foreign('buyer_decided_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['buyer_decided_by']);
            $table->dropColumn([
                'awaiting_confirmation_at',
                'buyer_decision',
                'buyer_decided_at',
                'buyer_decided_by',
            ]);
        });
    }
};
