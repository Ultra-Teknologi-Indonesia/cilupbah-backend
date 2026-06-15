<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {

            $table->boolean('is_stored')->default(true)->after('is_consignment');
            $table->boolean('is_sold')->default(true)->after('is_stored');
            $table->boolean('is_purchased')->default(true)->after('is_sold');

            $table->integer('purchase_lead_time')->default(0)->after('is_purchased');
            $table->text('package_contents')->nullable()->after('purchase_lead_time');

            $table->uuid('sales_account_id')->nullable()->after('package_contents');
            $table->uuid('sales_return_account_id')->nullable()->after('sales_account_id');
            $table->uuid('inventory_account_id')->nullable()->after('sales_return_account_id');
            $table->uuid('cogs_account_id')->nullable()->after('inventory_account_id');

            $table->foreign('sales_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('sales_return_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('inventory_account_id')->references('id')->on('accounts')->nullOnDelete();
            $table->foreign('cogs_account_id')->references('id')->on('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['sales_account_id']);
            $table->dropForeign(['sales_return_account_id']);
            $table->dropForeign(['inventory_account_id']);
            $table->dropForeign(['cogs_account_id']);
            $table->dropColumn([
                'is_stored',
                'is_sold',
                'is_purchased',
                'purchase_lead_time',
                'package_contents',
                'sales_account_id',
                'sales_return_account_id',
                'inventory_account_id',
                'cogs_account_id',
            ]);
        });
    }
};
