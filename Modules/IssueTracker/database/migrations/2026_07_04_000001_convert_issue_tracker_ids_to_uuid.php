<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('issue_tracker_comments', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
        });

        Schema::table('issue_tracker_attachments', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
            $table->dropForeign(['comment_id']);
        });

        Schema::table('issue_tracker_activities', function (Blueprint $table) {
            $table->dropForeign(['issue_id']);
        });

        DB::statement("ALTER TABLE issue_tracker_issues ALTER COLUMN id DROP DEFAULT");
        DB::statement("ALTER TABLE issue_tracker_comments ALTER COLUMN id DROP DEFAULT");

        $columns = [
            'issue_tracker_issues' => ['id'],
            'issue_tracker_comments' => ['id', 'issue_id'],
            'issue_tracker_attachments' => ['issue_id', 'comment_id'],
            'issue_tracker_activities' => ['issue_id'],
        ];

        foreach ($columns as $table => $cols) {
            foreach ($cols as $col) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$col} TYPE UUID USING LPAD({$col}::text, 32, '0')::uuid");
            }
        }

        Schema::table('issue_tracker_comments', function (Blueprint $table) {
            $table->foreign('issue_id')->references('id')->on('issue_tracker_issues')->cascadeOnDelete();
        });

        Schema::table('issue_tracker_attachments', function (Blueprint $table) {
            $table->foreign('issue_id')->references('id')->on('issue_tracker_issues')->cascadeOnDelete();
            $table->foreign('comment_id')->references('id')->on('issue_tracker_comments')->cascadeOnDelete();
        });

        Schema::table('issue_tracker_activities', function (Blueprint $table) {
            $table->foreign('issue_id')->references('id')->on('issue_tracker_issues')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        //
    }
};
