<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->longText('original_exception')->nullable();
            $table->text('original_exception_class')->nullable();
            $table->unsignedInteger('original_attempt')->nullable();
            $table->timestamp('original_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->dropColumn([
                'original_exception',
                'original_exception_class',
                'original_attempt',
                'original_failed_at',
            ]);
        });
    }
};
