<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->timestamp('order_pull_window_from')->nullable();
            $table->timestamp('order_pull_window_to')->nullable();
            $table->unsignedTinyInteger('order_pull_attempts')->default(0);
            $table->timestamp('order_pull_next_attempt_at')->nullable();
            $table->index('order_pull_next_attempt_at', 'idx_channel_shops_order_pull_next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->dropColumn([
                'order_pull_window_from',
                'order_pull_window_to',
                'order_pull_attempts',
                'order_pull_next_attempt_at',
            ]);
        });
    }
};
