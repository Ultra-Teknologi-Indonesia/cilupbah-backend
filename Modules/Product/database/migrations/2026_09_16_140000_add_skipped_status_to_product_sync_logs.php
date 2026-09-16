<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSTRAINT = 'product_sync_logs_status_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE product_sync_logs DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement('ALTER TABLE product_sync_logs ADD CONSTRAINT '.self::CONSTRAINT." CHECK (status IN ('success', 'failed', 'pending', 'skipped')) NOT VALID");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('product_sync_logs')
            ->where('status', 'skipped')
            ->update(['status' => 'failed']);
        DB::statement('ALTER TABLE product_sync_logs DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement('ALTER TABLE product_sync_logs ADD CONSTRAINT '.self::CONSTRAINT." CHECK (status IN ('success', 'failed', 'pending')) NOT VALID");
    }
};
