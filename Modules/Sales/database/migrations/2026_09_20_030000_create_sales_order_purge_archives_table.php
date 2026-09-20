<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_purge_archives', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id')->index();
            $table->uuid('original_order_id')->unique();
            $table->string('salesorder_no')->nullable()->index();
            $table->string('channel_order_no')->nullable();
            $table->string('source', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->timestamp('transaction_date')->nullable()->index();
            $table->jsonb('snapshot');
            $table->timestamp('archived_at');

            $table->index(['source', 'channel_order_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_purge_archives');
    }
};
