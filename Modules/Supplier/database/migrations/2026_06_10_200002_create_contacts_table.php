<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('tax_id', 50)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('payment_term', 50)->default('NET30');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->string('type', 20)->default('CUSTOMER');
            $table->foreignUuid('category_id')->nullable()->constrained('contact_categories')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
