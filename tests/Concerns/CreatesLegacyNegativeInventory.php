<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;

trait CreatesLegacyNegativeInventory
{
    protected function createLegacyNegativeInventory(array $attributes): Inventory
    {
        DB::statement('ALTER TABLE inventories DROP CONSTRAINT IF EXISTS inventories_on_hand_non_negative_check');

        try {
            return Inventory::create($attributes);
        } finally {
            DB::statement(
                'ALTER TABLE inventories ADD CONSTRAINT inventories_on_hand_non_negative_check CHECK (on_hand >= 0) NOT VALID'
            );
        }
    }
}
