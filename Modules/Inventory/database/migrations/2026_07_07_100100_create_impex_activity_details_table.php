<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impex_activity_details', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('impex_activity_id');
            $table->string('reference_id');
            $table->string('description');
            $table->timestamp('created_at')->nullable();

            $table->foreign('impex_activity_id')->references('id')->on('impex_activities')->cascadeOnDelete();
            $table->index('impex_activity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impex_activity_details');
    }
};
