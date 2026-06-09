<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * source_id bersifat polimorfik (PURCHASE_ORDER, SALES_RETURN, TRANSIT_IN, dll)
     * yang seluruh sumbernya memakai UUID. Sebelumnya bertipe unsignedBigInteger
     * sehingga tidak bisa menyimpan UUID. Ubah menjadi uuid.
     */
    public function up(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn('source_id');
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->uuid('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inbounds', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn('source_id');
        });

        Schema::table('inbounds', function (Blueprint $table) {
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });
    }
};
