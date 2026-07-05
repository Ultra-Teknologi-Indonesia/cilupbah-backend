<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['deleted_by', 'delete_reason']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->softDeletes()->index();
            $table->string('deleted_by')->nullable();
            $table->text('delete_reason')->nullable();
        });
    }
};
