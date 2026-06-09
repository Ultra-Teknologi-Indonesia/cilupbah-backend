<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // source_id bersifat polymorphic (purchase_order, marketplace_return, dll.).
        // Semua sumber kini ber-id UUID, namun kolom masih unsignedBigInteger → ubah ke UUID.
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE inbounds ALTER COLUMN source_id TYPE UUID USING LPAD(source_id::text, 32, '0')::uuid");
        }

        Schema::table('inbounds', function (Blueprint $table) {
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE inbounds ALTER COLUMN source_id TYPE BIGINT USING NULLIF(source_id::text, '')::bigint");
        }

        Schema::table('inbounds', function (Blueprint $table) {
            $table->index(['source_type', 'source_id']);
        });
    }
};
