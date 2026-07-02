<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_settings', function (Blueprint $table) {
            $table->boolean('auto_receive')->default(false)->after('auto_accept');
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_settings', function (Blueprint $table) {
            $table->dropColumn('auto_receive');
        });
    }
};
