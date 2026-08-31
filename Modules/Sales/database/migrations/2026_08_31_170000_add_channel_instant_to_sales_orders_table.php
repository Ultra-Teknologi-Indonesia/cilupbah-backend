<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_orders', 'channel_instant')) {
                $table->boolean('channel_instant')->nullable()->after('shipping_type');
                $table->index('channel_instant', 'idx_so_channel_instant');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_orders', 'channel_instant')) {
                $table->dropIndex('idx_so_channel_instant');
                $table->dropColumn('channel_instant');
            }
        });
    }
};
