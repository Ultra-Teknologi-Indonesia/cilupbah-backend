<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_categories', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('is_leaf');
        });
    }

    public function down(): void
    {
        Schema::table('channel_categories', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
