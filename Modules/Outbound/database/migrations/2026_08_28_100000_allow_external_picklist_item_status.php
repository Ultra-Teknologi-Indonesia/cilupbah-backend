<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CONSTRAINT = 'picklist_items_item_status_check';

    private const VALUES = [
        'PENDING',
        'PARTIAL',
        'COMPLETED',
        'SHORT',
        'REJECTED',
        'PROCESSED_EXTERNALLY',
    ];

    private const LEGACY_VALUES = [
        'PENDING',
        'PARTIAL',
        'COMPLETED',
        'SHORT',
        'REJECTED',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('picklist_items') || ! Schema::hasColumn('picklist_items', 'item_status')) {
            return;
        }

        DB::statement('ALTER TABLE picklist_items DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->constraintSql(self::VALUES));
    }

    public function down(): void
    {
        if (! Schema::hasTable('picklist_items') || ! Schema::hasColumn('picklist_items', 'item_status')) {
            return;
        }

        DB::statement('ALTER TABLE picklist_items DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
        DB::statement($this->constraintSql(self::LEGACY_VALUES));
    }

    private function constraintSql(array $values): string
    {
        $list = collect($values)
            ->map(fn (string $value): string => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        return 'ALTER TABLE picklist_items ADD CONSTRAINT '.self::CONSTRAINT
            .' CHECK (item_status IS NULL OR item_status IN ('.$list.')) NOT VALID';
    }
};
