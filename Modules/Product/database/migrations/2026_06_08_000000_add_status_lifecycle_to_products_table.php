<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // State machine produk: download -> in_review -> master -> archived
            $table->enum('status', ['download', 'in_review', 'master', 'archived'])
                ->default('master')
                ->after('is_active')
                ->index();

            // Audit trail verifikasi (diisi saat approve ke master)
            $table->timestamp('verified_at')->nullable()->after('status');
            $table->uuid('verified_by')->nullable()->after('verified_at');

            // Audit trail arsip
            $table->timestamp('archived_at')->nullable()->after('verified_by');
            $table->uuid('archived_by')->nullable()->after('archived_at');
            $table->string('archive_reason')->nullable()->after('archived_by');

            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropForeign(['archived_by']);
            $table->dropColumn([
                'status',
                'verified_at',
                'verified_by',
                'archived_at',
                'archived_by',
                'archive_reason',
            ]);
        });
    }
};
