<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'cost_per_unit')) {
                $table->decimal('cost_per_unit', 15, 4)->nullable()->after('balance');
            }
            if (! Schema::hasColumn('inventory_movements', 'total_cost')) {
                $table->decimal('total_cost', 15, 2)->nullable()->after('cost_per_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_movements', 'total_cost')) {
                $table->dropColumn('total_cost');
            }
            if (Schema::hasColumn('inventory_movements', 'cost_per_unit')) {
                $table->dropColumn('cost_per_unit');
            }
        });
    }
};
