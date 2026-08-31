<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->string('reference_number', 255)
                ->nullable()
                ->after('transaction_number');
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropIndex(['reference_number']);
            $table->dropColumn('reference_number');
        });
    }
};
