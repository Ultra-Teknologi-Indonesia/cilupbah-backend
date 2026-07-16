<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assignment_history')) {
            return;
        }

        DB::statement('ALTER TABLE assignment_history ALTER COLUMN id TYPE uuid USING id::uuid');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN subject_id TYPE uuid USING subject_id::uuid');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN from_user_id TYPE uuid USING from_user_id::uuid');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN to_user_id TYPE uuid USING to_user_id::uuid');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN actor_id TYPE uuid USING actor_id::uuid');
    }

    public function down(): void
    {
        if (! Schema::hasTable('assignment_history')) {
            return;
        }

        DB::statement('ALTER TABLE assignment_history ALTER COLUMN id TYPE char(26) USING id::text');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN subject_id TYPE char(26) USING subject_id::text');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN from_user_id TYPE char(26) USING from_user_id::text');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN to_user_id TYPE char(26) USING to_user_id::text');
        DB::statement('ALTER TABLE assignment_history ALTER COLUMN actor_id TYPE char(26) USING actor_id::text');
    }
};
