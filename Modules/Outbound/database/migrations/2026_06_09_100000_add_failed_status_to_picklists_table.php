<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE picklists DROP CONSTRAINT IF EXISTS picklists_status_check");
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE picklists ADD CONSTRAINT picklists_status_check CHECK (status IN ('DRAFT', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED'))");
        } else {
            Schema::table('picklists', function (Blueprint $table) {
                $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'FAILED', 'CANCELLED'])
                    ->default('DRAFT')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE picklists DROP CONSTRAINT IF EXISTS picklists_status_check");
            \Illuminate\Support\Facades\DB::statement("ALTER TABLE picklists ADD CONSTRAINT picklists_status_check CHECK (status IN ('DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'))");
        } else {
            Schema::table('picklists', function (Blueprint $table) {
                $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])
                    ->default('DRAFT')
                    ->change();
            });
        }
    }
};
