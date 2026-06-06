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
        Schema::create('user_histories', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('actor_id', 32)->nullable()->comment('User who performed the action');
            $table->string('target_user_id', 32)->comment('User who was affected');
            $table->enum('action', ['created', 'updated', 'deleted', 'force_logged_out']);
            $table->timestamps();

            $table->foreign('actor_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_histories');
    }
};
