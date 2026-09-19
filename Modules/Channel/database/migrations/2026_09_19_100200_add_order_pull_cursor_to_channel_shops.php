<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->text('order_pull_cursor')->nullable()->after('order_pull_window_to');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shops', function (Blueprint $table): void {
            $table->dropColumn('order_pull_cursor');
        });
    }
};
