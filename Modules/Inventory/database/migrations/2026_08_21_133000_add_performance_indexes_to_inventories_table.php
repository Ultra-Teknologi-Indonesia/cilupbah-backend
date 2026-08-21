<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventories_bin_active ON inventories (bin_id, on_hand, on_order) WHERE (on_hand > 0 OR on_order > 0)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inventories_loc_item_avail ON inventories (location_id, item_id, on_hand, on_order)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_location_bins_loc_zone ON location_bins (location_id, zone_id)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_putaways_loc_status_created ON putaways (location_id, status, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_putaways_created_at_desc ON putaways (created_at DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inbounds_loc_status_created ON inbounds (location_id, status, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inbounds_created_at_desc ON inbounds (created_at DESC)');

        DB::statement('CREATE INDEX IF NOT EXISTS idx_inv_transfers_src_loc_created ON inventory_transfers (source_location_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inv_transfers_dst_loc_created ON inventory_transfers (destination_location_id, created_at DESC)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_inv_transfers_created_at_desc ON inventory_transfers (created_at DESC)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_inventories_bin_active');
        DB::statement('DROP INDEX IF EXISTS idx_inventories_loc_item_avail');
        DB::statement('DROP INDEX IF EXISTS idx_location_bins_loc_zone');
        DB::statement('DROP INDEX IF EXISTS idx_putaways_loc_status_created');
        DB::statement('DROP INDEX IF EXISTS idx_putaways_created_at_desc');
        DB::statement('DROP INDEX IF EXISTS idx_inbounds_loc_status_created');
        DB::statement('DROP INDEX IF EXISTS idx_inbounds_created_at_desc');
        DB::statement('DROP INDEX IF EXISTS idx_inv_transfers_src_loc_created');
        DB::statement('DROP INDEX IF EXISTS idx_inv_transfers_dst_loc_created');
        DB::statement('DROP INDEX IF EXISTS idx_inv_transfers_created_at_desc');
    }
};
