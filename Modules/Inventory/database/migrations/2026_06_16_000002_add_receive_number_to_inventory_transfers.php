<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {

            $table->string('receive_number')->nullable()->after('transfer_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transfers', function (Blueprint $table) {
            $table->dropColumn('receive_number');
        });
    }
};
