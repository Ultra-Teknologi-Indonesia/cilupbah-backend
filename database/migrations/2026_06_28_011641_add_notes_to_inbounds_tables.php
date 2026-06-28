<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
        Schema::table('inbound_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
