<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_channel_mappings', function (Blueprint $table) {
            $table->boolean('is_pull_default')->default(false)->after('channel_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('category_channel_mappings', function (Blueprint $table) {
            $table->dropColumn('is_pull_default');
        });
    }
};
