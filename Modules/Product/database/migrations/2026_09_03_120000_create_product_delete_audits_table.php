<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_delete_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->unique();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name', 150)->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->string('request_id', 100)->nullable()->index();
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('requested_count');
            $table->json('product_snapshots');
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_message')->nullable();
            $table->string('media_cleanup_status', 32)->default('pending');
            $table->text('media_cleanup_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_delete_audits');
    }
};
