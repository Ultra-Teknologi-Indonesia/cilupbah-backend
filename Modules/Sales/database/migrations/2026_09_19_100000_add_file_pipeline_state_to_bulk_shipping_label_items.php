<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bulk_shipping_label_items', function (Blueprint $table): void {
            $table->string('raw_pdf_path')->nullable()->after('pdf_bytes');
            $table->string('ready_pdf_path')->nullable()->after('raw_pdf_path');
        });
    }

    public function down(): void
    {
        Schema::table('bulk_shipping_label_items', function (Blueprint $table): void {
            $table->dropColumn(['raw_pdf_path', 'ready_pdf_path']);
        });
    }
};
