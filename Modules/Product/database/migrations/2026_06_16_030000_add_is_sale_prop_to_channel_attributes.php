<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('channel_attributes', function (Blueprint $table) {
            $table->boolean('is_sale_prop')->default(false)->after('is_multiple');
        });
    }

    public function down(): void
    {
        Schema::table('channel_attributes', function (Blueprint $table) {
            $table->dropColumn('is_sale_prop');
        });
    }
};
