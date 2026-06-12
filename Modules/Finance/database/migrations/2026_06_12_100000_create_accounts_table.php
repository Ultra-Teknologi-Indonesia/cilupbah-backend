<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Chart of Accounts (Jubelio: /accounts/lookup/all). */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_code', 20)->unique();
            $table->string('account_name', 150);
            $table->string('account_type', 20); // asset|liability|equity|revenue|expense
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['account_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
