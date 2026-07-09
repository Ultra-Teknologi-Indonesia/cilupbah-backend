<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->string('reason_category', 30)->nullable()->after('reason');
            $table->index('reason_category', 'sales_returns_reason_category_index');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropIndex('sales_returns_reason_category_index');
            $table->dropColumn('reason_category');
        });
    }
};
