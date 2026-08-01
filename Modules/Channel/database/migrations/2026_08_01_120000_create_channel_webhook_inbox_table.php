<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Channel\Enums\WebhookInboxStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_inbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('channel');
            $table->string('shop_id')->nullable();
            $table->string('event_key')->unique();
            $table->string('event_type')->nullable();
            $table->jsonb('payload');
            $table->string('status')->default(WebhookInboxStatus::RECEIVED->value);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_inbox');
    }
};
