<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_tracker_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('platform')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('issue_tracker_issues', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_token', 32)->unique();
            $table->string('title');
            $table->text('description');
            $table->string('platform', 20);
            $table->foreignId('category_id')->nullable()->constrained('issue_tracker_categories')->nullOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->string('reporter_name');
            $table->string('reporter_contact')->nullable();
            $table->string('assigned_to')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('platform');
            $table->index('priority');
            $table->index('created_at');
        });

        Schema::create('issue_tracker_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issue_tracker_issues')->cascadeOnDelete();
            $table->text('body');
            $table->string('author_type', 20);
            $table->string('author_name');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();

            $table->index(['issue_id', 'created_at']);
        });

        Schema::create('issue_tracker_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issue_tracker_issues')->cascadeOnDelete();
            $table->foreignId('comment_id')->nullable()->constrained('issue_tracker_comments')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size')->default(0);
            $table->timestamps();
        });

        Schema::create('issue_tracker_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issue_id')->constrained('issue_tracker_issues')->cascadeOnDelete();
            $table->string('action', 50);
            $table->string('actor_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['issue_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_tracker_activities');
        Schema::dropIfExists('issue_tracker_attachments');
        Schema::dropIfExists('issue_tracker_comments');
        Schema::dropIfExists('issue_tracker_issues');
        Schema::dropIfExists('issue_tracker_categories');
    }
};
