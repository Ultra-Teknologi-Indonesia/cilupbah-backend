<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->dropColumn(['batch_no', 'serial_no']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustment_items', function (Blueprint $table) {
            $table->string('batch_no', 100)->nullable()->after('bin_id');
            $table->string('serial_no', 100)->nullable()->after('batch_no');
        });
    }
};
