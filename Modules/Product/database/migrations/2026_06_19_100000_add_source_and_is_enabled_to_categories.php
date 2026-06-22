<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('source')->default('system')->after('is_leaf');
            $table->boolean('is_enabled')->default(false)->after('source');
            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['is_enabled']);
            $table->dropColumn(['source', 'is_enabled']);
        });
    }
};
