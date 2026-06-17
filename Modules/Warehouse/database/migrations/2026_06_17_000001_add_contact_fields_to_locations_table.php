<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('default_warehouse_user');
            $table->string('email', 255)->nullable()->after('phone');

            $table->string('coordinate', 100)->nullable()->after('email');

            $table->string('location_type', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['phone', 'email', 'coordinate']);
            $table->string('location_type', 50)->nullable(false)->change();
        });
    }
};
