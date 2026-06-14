<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * No-op: tabel `promotions` & `promotion_items` kini dibuat oleh migrasi kanonik
     * Modules/Inventory/.../2026_06_11_000001_create_promotions_table.php (skema dengan
     * variant_id, promo_price, min_qty, dst). Migrasi ini dikosongkan agar tidak bentrok
     * ("relation promotions already exists") saat migrate:fresh.
     */
    public function up(): void
    {
        // intentionally empty — superseded by Inventory promotions migration
    }

    public function down(): void
    {
        // intentionally empty
    }
};
