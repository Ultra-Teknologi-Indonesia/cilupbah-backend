<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('packlist_no', 50)->unique();
            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
            $table->uuid('packer_id')->nullable();
            $table->foreign('packer_id')->references('id')->on('users')->nullOnDelete();
            $table->string('assigned_by', 100)->nullable();
            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->restrictOnDelete();
            $table->uuid('picklist_id')->nullable();
            $table->foreign('picklist_id')->references('id')->on('picklists')->nullOnDelete();
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->integer('package_count')->default(1);
            $table->text('notes')->nullable();
            $table->string('created_by', 100);
            $table->timestamps();

            $table->index('status');
            $table->index(['packer_id', 'status']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packlists');
    }
};
