<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'inventories_on_hand_non_negative_check';

    public function up(): void
    {
        if (! Schema::hasTable('inventories') || ! Schema::hasColumn('inventories', 'on_hand')) {
            return;
        }

        $exists = DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)',
            [self::CONSTRAINT, 'inventories'],
        );

        if ($exists) {
            return;
        }

        // NOT VALID protects every new insert/update immediately while allowing
        // existing legacy negative rows to be reconciled explicitly first.
        DB::statement(
            'ALTER TABLE inventories ADD CONSTRAINT '.self::CONSTRAINT.' CHECK (on_hand >= 0) NOT VALID'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inventories DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
    }
};
