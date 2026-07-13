<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_profile', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('legal_name')->default('PT ULTRA TEKNOLOGI INDONESIA');
            $table->string('brand_name')->nullable();
            $table->string('npwp')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->uuid('logo_media_id')->nullable();
            $table->uuid('signature_media_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profile');
    }
};
