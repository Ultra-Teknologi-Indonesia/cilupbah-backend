<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('return_tracking_number')->nullable()->after('channel_shop_id');
            $table->string('return_carrier')->nullable()->after('return_tracking_number');
            $table->timestamp('return_shipped_at')->nullable()->after('return_carrier');
            $table->timestamp('tracking_synced_at')->nullable()->after('return_shipped_at');
            $table->index('return_tracking_number', 'sales_returns_return_tracking_number_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex('sales_returns_return_tracking_number_index');
            $table->dropColumn([
                'return_tracking_number',
                'return_carrier',
                'return_shipped_at',
                'tracking_synced_at',
            ]);
        });
    }
};
