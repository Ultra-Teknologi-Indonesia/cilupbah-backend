<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->char('id', 26)->primary();
            $table->string('key', 128);
            $table->string('endpoint', 255);
            $table->char('user_id', 26)->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['key', 'endpoint'], 'idempotency_keys_key_endpoint_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
