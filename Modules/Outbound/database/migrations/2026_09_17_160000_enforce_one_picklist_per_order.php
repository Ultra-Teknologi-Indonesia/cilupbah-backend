<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FUNCTION = 'outbound_prevent_order_in_multiple_picklists';

    private const CLEANUP_FUNCTION = 'outbound_cleanup_picklist_order_assignment';

    private const TRIGGER = 'trg_picklist_items_one_picklist_per_order';

    private const CLEANUP_TRIGGER = 'trg_picklist_items_cleanup_order_assignment';

    public function up(): void
    {
        if (! Schema::hasTable('picklists') || ! Schema::hasTable('picklist_items')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::create('picklist_order_assignments', function ($table): void {
            $table->uuid('order_id')->primary();
            $table->uuid('picklist_id');
            $table->foreign('order_id')->references('id')->on('sales_orders')->restrictOnDelete();
            $table->foreign('picklist_id')->references('id')->on('picklists')->cascadeOnDelete();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
INSERT INTO picklist_order_assignments (order_id, picklist_id, created_at, updated_at)
SELECT DISTINCT ON (pi.order_id)
    pi.order_id,
    pi.picklist_id,
    NOW(),
    NOW()
FROM picklist_items pi
JOIN picklists p ON p.id = pi.picklist_id
ORDER BY pi.order_id, p.created_at ASC NULLS FIRST, p.id ASC
ON CONFLICT (order_id) DO NOTHING
SQL
        );

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION outbound_prevent_order_in_multiple_picklists()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    INSERT INTO picklist_order_assignments (order_id, picklist_id, created_at, updated_at)
    VALUES (NEW.order_id, NEW.picklist_id, NOW(), NOW())
    ON CONFLICT (order_id) DO NOTHING;

    IF NOT EXISTS (
        SELECT 1
        FROM picklist_order_assignments assignment
        WHERE assignment.order_id = NEW.order_id
          AND assignment.picklist_id = NEW.picklist_id
    ) THEN
        RAISE EXCEPTION USING
            ERRCODE = '23505',
            MESSAGE = format('Order %s sudah terdaftar di picklist lain', NEW.order_id);
    END IF;
    RETURN NEW;
END;
$function$
SQL
        );

        DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON picklist_items');
        DB::statement('CREATE TRIGGER '.self::TRIGGER
            .' BEFORE INSERT OR UPDATE OF order_id, picklist_id ON picklist_items'
            .' FOR EACH ROW EXECUTE FUNCTION '.self::FUNCTION.'()');

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION outbound_cleanup_picklist_order_assignment()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF TG_OP = 'DELETE' THEN
        DELETE FROM picklist_order_assignments assignment
        WHERE assignment.order_id = OLD.order_id
          AND NOT EXISTS (
              SELECT 1 FROM picklist_items remaining
              WHERE remaining.order_id = OLD.order_id
          );
    ELSIF OLD.order_id IS DISTINCT FROM NEW.order_id THEN
        DELETE FROM picklist_order_assignments assignment
        WHERE assignment.order_id = OLD.order_id
          AND NOT EXISTS (
              SELECT 1 FROM picklist_items remaining
              WHERE remaining.order_id = OLD.order_id
          );
    END IF;

    RETURN NULL;
END;
$function$
SQL
        );

        DB::statement('DROP TRIGGER IF EXISTS '.self::CLEANUP_TRIGGER.' ON picklist_items');
        DB::statement('CREATE TRIGGER '.self::CLEANUP_TRIGGER
            .' AFTER DELETE OR UPDATE OF order_id ON picklist_items'
            .' FOR EACH ROW EXECUTE FUNCTION '.self::CLEANUP_FUNCTION.'()');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('picklist_items')) {
            DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON picklist_items');
            DB::statement('DROP TRIGGER IF EXISTS '.self::CLEANUP_TRIGGER.' ON picklist_items');
        }

        DB::statement('DROP FUNCTION IF EXISTS '.self::FUNCTION.'()');
        DB::statement('DROP FUNCTION IF EXISTS '.self::CLEANUP_FUNCTION.'()');

        Schema::dropIfExists('picklist_order_assignments');
    }
};
