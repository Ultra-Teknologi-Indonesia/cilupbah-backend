<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('picklists', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED'])
                ->default('DRAFT')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('picklists', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])
                ->default('DRAFT')
                ->change();
        });
    }
};
