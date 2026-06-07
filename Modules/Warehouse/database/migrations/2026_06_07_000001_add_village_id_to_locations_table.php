<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('village_id', 10)->nullable()->after('address');

            $table->foreign('village_id')
                ->references('id')
                ->on('villages')
                ->nullOnDelete();

            $table->index('village_id');

            $table->dropColumn(['area', 'city', 'province']);
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['village_id']);
            $table->dropColumn('village_id');

            $table->string('area', 100)->nullable()->after('address');
            $table->string('city', 100)->nullable()->after('area');
            $table->string('province', 100)->nullable()->after('city');
        });
    }
};
