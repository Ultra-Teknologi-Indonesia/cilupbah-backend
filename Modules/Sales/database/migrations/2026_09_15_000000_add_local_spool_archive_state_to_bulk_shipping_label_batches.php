<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
            $table->string('print_pdf_path')->nullable()->after('merged_pdf_bytes');
            $table->string('archive_status', 24)->default('pending')->after('print_pdf_path');
            $table->string('archive_checksum', 64)->nullable()->after('archive_status');
            $table->unsignedBigInteger('archive_pdf_bytes')->nullable()->after('archive_checksum');
            $table->text('archive_error')->nullable()->after('archive_pdf_bytes');
            $table->timestamp('archived_at')->nullable()->after('archive_error');
            $table->index(['archive_status', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
            $table->dropIndex(['archive_status', 'archived_at']);
            $table->dropColumn([
                'print_pdf_path',
                'archive_status',
                'archive_checksum',
                'archive_pdf_bytes',
                'archive_error',
                'archived_at',
            ]);
        });
    }
};
