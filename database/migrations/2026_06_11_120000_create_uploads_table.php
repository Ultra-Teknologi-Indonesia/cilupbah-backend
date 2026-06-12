<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel pemilik (owner) generik untuk semua file yang diunggah lewat endpoint media.
     * File fisik dikelola Spatie Media Library; tabel lain cukup menyimpan media UUID/URL.
     */
    public function up(): void
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
