<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_orders', 'location_id')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->foreignUuid('location_id')->nullable()->after('source')->constrained('locations')->nullOnDelete();
                $table->index(['location_id', 'status'], 'sales_orders_location_status_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'location_id')) {
            Schema::table('sales_orders', function (Blueprint $table): void {
                $table->dropForeign(['location_id']);
                $table->dropIndex('sales_orders_location_status_idx');
                $table->dropColumn('location_id');
            });
        }
    }
};
