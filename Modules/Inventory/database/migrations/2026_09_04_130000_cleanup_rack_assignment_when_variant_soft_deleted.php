<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUNCTION = 'cleanup_sku_rack_assignment_after_variant_soft_delete';

    private const TRIGGER = 'trg_cleanup_sku_rack_assignment_after_variant_soft_delete';

    private const PRODUCT_FUNCTION = 'cleanup_sku_rack_assignment_after_product_soft_delete';

    private const PRODUCT_TRIGGER = 'trg_cleanup_sku_rack_assignment_after_product_soft_delete';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION cleanup_sku_rack_assignment_after_variant_soft_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
        DELETE FROM sku_rack_assignments
        WHERE item_id = NEW.id;
    END IF;

    RETURN NEW;
END;
$function$;

DROP TRIGGER IF EXISTS trg_cleanup_sku_rack_assignment_after_variant_soft_delete
    ON product_variants;

CREATE TRIGGER trg_cleanup_sku_rack_assignment_after_variant_soft_delete
AFTER UPDATE OF deleted_at ON product_variants
FOR EACH ROW
WHEN (OLD.deleted_at IS DISTINCT FROM NEW.deleted_at)
EXECUTE FUNCTION cleanup_sku_rack_assignment_after_variant_soft_delete();

CREATE OR REPLACE FUNCTION cleanup_sku_rack_assignment_after_product_soft_delete()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
BEGIN
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL THEN
        DELETE FROM sku_rack_assignments AS assignments
        USING product_variants AS variants
        WHERE assignments.item_id = variants.id
          AND variants.product_id = NEW.id;
    END IF;

    RETURN NEW;
END;
$function$;

DROP TRIGGER IF EXISTS trg_cleanup_sku_rack_assignment_after_product_soft_delete
    ON products;

CREATE TRIGGER trg_cleanup_sku_rack_assignment_after_product_soft_delete
AFTER UPDATE OF deleted_at ON products
FOR EACH ROW
WHEN (OLD.deleted_at IS DISTINCT FROM NEW.deleted_at)
EXECUTE FUNCTION cleanup_sku_rack_assignment_after_product_soft_delete();

DELETE FROM sku_rack_assignments
WHERE NOT EXISTS (
    SELECT 1
    FROM product_variants AS variants
    JOIN products AS products ON products.id = variants.product_id
    WHERE variants.id = sku_rack_assignments.item_id
      AND variants.deleted_at IS NULL
      AND products.deleted_at IS NULL
);

ALTER TABLE sku_rack_assignments
    ADD CONSTRAINT sku_rack_assignments_item_id_foreign
    FOREIGN KEY (item_id) REFERENCES product_variants(id) ON DELETE CASCADE;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(
            'DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON product_variants;'
            .' DROP FUNCTION IF EXISTS '.self::FUNCTION.'();'
            .' DROP TRIGGER IF EXISTS '.self::PRODUCT_TRIGGER.' ON products;'
            .' DROP FUNCTION IF EXISTS '.self::PRODUCT_FUNCTION.'();'
            .' ALTER TABLE sku_rack_assignments DROP CONSTRAINT IF EXISTS sku_rack_assignments_item_id_foreign;'
        );
    }
};
