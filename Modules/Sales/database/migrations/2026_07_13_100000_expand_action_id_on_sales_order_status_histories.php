<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_status_histories', function (Blueprint $table) {
            $table->string('action_id', 16)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_status_histories', function (Blueprint $table) {
            $table->string('action_id', 8)->change();
        });
    }
};
