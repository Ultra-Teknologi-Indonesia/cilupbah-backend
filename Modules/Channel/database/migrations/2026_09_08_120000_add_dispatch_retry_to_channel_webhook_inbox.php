<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->timestamp('next_attempt_at')->nullable();
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('channel_webhook_inbox', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_attempt_at']);
            $table->dropColumn('next_attempt_at');
        });
    }
};
