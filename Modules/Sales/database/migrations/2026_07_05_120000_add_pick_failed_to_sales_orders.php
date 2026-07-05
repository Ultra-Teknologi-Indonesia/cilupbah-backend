<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->timestamp('pick_failed_at')->nullable()->index();
            $table->string('pick_failed_by')->nullable();
            $table->text('pick_fail_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['pick_failed_at', 'pick_failed_by', 'pick_fail_reason']);
        });
    }
};
