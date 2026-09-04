<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;

final class RackAssignmentCleanupService
{

    public function removeForVariants(array $variantIds): int
    {
        $variantIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): string => (string) $id, $variantIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($variantIds === []) {
            return 0;
        }

        return DB::table('sku_rack_assignments')
            ->whereIn('item_id', $variantIds)
            ->delete();
    }
}
