<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Jurnal umum (Jubelio: /journal/). journal_type null = otomatis, 'Manual Jurnal' = manual. */
    public function up(): void
    {
        Schema::create('journals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('journal_no', 30)->unique();
            $table->timestamp('transaction_date');
            $table->string('journal_type', 30)->nullable();
            $table->string('source_doc_type', 50)->nullable();
            $table->uuid('source_doc_id')->nullable();
            $table->string('source_doc_no', 100)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_debit', 18, 4)->default(0);
            $table->decimal('total_credit', 18, 4)->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            // Idempoten: satu dokumen sumber = satu jurnal.
            $table->unique(['source_doc_type', 'source_doc_id']);
            $table->index('transaction_date');
            $table->index('journal_type');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
