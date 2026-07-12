<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->string('device_model', 191)->nullable()->after('agent_browser');
            $table->string('device_manufacturer', 100)->nullable()->after('device_model');
        });
    }

    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->dropColumn(['device_model', 'device_manufacturer']);
        });
    }
};
