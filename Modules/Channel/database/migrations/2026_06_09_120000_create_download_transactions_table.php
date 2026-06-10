<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS download_transactions_trx_seq');

        Schema::create('download_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('trx_no')->unique();
            $table->uuid('channel_shop_id');
            $table->uuid('executed_by')->nullable();
            $table->enum('state', ['queued', 'downloading', 'done', 'failed'])->default('queued');
            $table->integer('all_product')->default(0);
            $table->integer('total_downloaded')->default(0);
            $table->integer('progress_percent')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('channel_shop_id')->references('id')->on('channel_shops')->cascadeOnDelete();
            $table->foreign('executed_by')->references('id')->on('users')->nullOnDelete();
            $table->index('state');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_transactions');
        DB::statement('DROP SEQUENCE IF EXISTS download_transactions_trx_seq');
    }
};
