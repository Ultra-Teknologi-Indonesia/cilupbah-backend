<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->uuid('courier_id')->nullable()->after('courier_phone');
            $table->foreign('courier_id')
                ->references('id')->on('couriers')
                ->nullOnDelete();
            $table->index('courier_id');
        });

        DB::statement(<<<'SQL'
            UPDATE sales_orders so
            SET    courier_id = c.id
            FROM   couriers c
            WHERE  so.courier_id IS NULL
              AND  so.courier_name IS NOT NULL
              AND  c.name = so.courier_name
        SQL);
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign(['courier_id']);
            $table->dropIndex(['courier_id']);
            $table->dropColumn('courier_id');
        });
    }
};
