<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_categories', function (Blueprint $table) {
            $table->string('type', 20)->default('BOTH')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('contact_categories', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
