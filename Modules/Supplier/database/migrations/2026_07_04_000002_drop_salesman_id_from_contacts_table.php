<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'salesman_id')) {
                $table->dropForeign(['salesman_id']);
                $table->dropColumn('salesman_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignUuid('salesman_id')
                ->nullable()
                ->after('npwp_address')
                ->constrained('salesmen')
                ->nullOnDelete();
        });
    }
};
