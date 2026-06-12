<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event');                 // salah satu dari 9 event, atau '*'
            $table->string('target_url');
            $table->string('secret');                // untuk HMAC-SHA256
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['event', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_subscriptions');
    }
};
