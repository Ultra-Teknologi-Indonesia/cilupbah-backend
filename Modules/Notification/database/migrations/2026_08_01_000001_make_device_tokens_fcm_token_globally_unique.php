<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement(<<<'SQL'
            DELETE FROM device_tokens dt
            USING device_tokens keep
            WHERE dt.fcm_token = keep.fcm_token
              AND dt.id <> keep.id
              AND (
                    dt.updated_at < keep.updated_at
                 OR (dt.updated_at = keep.updated_at AND dt.id < keep.id)
              )
        SQL);

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'fcm_token']);
            $table->unique('fcm_token');
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropUnique(['fcm_token']);
            $table->unique(['user_id', 'fcm_token']);
        });
    }
};
