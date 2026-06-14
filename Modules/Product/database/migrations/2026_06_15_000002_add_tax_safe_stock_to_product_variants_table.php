<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {

            $table->integer('safe_stock')->default(0)->after('min_stock');

            $table->unsignedBigInteger('sales_tax_id')->nullable()->after('tax_rate');
            $table->unsignedBigInteger('purchase_tax_id')->nullable()->after('sales_tax_id');

            $table->foreign('sales_tax_id')->references('id')->on('taxes')->nullOnDelete();
            $table->foreign('purchase_tax_id')->references('id')->on('taxes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropForeign(['sales_tax_id']);
            $table->dropForeign(['purchase_tax_id']);
            $table->dropColumn(['safe_stock', 'sales_tax_id', 'purchase_tax_id']);
        });
    }
};
