<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
            $table->timestamp('file_purged_at')->nullable()->after('merged_pdf_bytes');
            $table->index(['file_purged_at', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bulk_shipping_label_batches', function (Blueprint $table): void {
            $table->dropIndex(['file_purged_at', 'finished_at']);
            $table->dropColumn('file_purged_at');
        });
    }
};
