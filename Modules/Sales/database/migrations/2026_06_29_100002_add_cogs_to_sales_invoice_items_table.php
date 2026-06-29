<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_invoice_items', 'cogs_per_unit')) {
                $table->decimal('cogs_per_unit', 15, 4)->nullable()->after('subtotal');
            }
            if (! Schema::hasColumn('sales_invoice_items', 'total_cogs')) {
                $table->decimal('total_cogs', 15, 2)->nullable()->after('cogs_per_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('sales_invoice_items', 'total_cogs')) {
                $table->dropColumn('total_cogs');
            }
            if (Schema::hasColumn('sales_invoice_items', 'cogs_per_unit')) {
                $table->dropColumn('cogs_per_unit');
            }
        });
    }
};
