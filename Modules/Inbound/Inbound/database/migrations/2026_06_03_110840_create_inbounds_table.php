<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('inbounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->string('transaction_number', 100)->unique();
            $table->string('reference_number', 100)->nullable()->index();
            $table->string('type', 30);
            $table->string('status', 30)->default('DRAFT');
            $table->dateTime('expected_date');
            $table->string('created_by', 100);
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbounds');
    }
};
