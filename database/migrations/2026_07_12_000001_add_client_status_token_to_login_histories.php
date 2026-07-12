<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->enum('client_type', ['web', 'mobile'])->default('web')->after('user_id');
            $table->enum('status', ['success', 'failed'])->default('success')->after('client_type');
            $table->unsignedBigInteger('token_id')->nullable()->after('status');
            $table->string('email_attempt', 191)->nullable()->after('token_id');
        });

        DB::statement('ALTER TABLE login_histories ALTER COLUMN user_id DROP NOT NULL');

        Schema::table('login_histories', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
            $table->index('token_id');
        });
    }

    public function down(): void
    {
        Schema::table('login_histories', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['token_id']);
            $table->dropColumn(['client_type', 'status', 'token_id', 'email_attempt']);
        });

        DB::statement('ALTER TABLE login_histories ALTER COLUMN user_id SET NOT NULL');
    }
};
