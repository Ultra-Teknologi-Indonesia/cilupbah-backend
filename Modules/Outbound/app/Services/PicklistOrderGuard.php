<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Facades\DB;
use Modules\Outbound\Exceptions\OutboundValidationException;

final class PicklistOrderGuard
{
    /**
     * Serialize every picklist creation attempt for the same order ids.
     *
     * This lock must be acquired inside the caller's transaction. Sorting the
     * ids prevents deadlocks when concurrent requests contain the same orders
     * in a different order.
     */
    public function lockForCreation(array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_map(
            static fn ($id): string => (string) $id,
            $orderIds,
        )));
        sort($orderIds);

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($orderIds as $orderId) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                "picklist:create:order:{$orderId}",
            ]);
        }
    }

    /**
     * An order may not be attached to a second picklist, regardless of the
     * existing picklist status. Re-processing must first remove/revert the
     * previous picklist association through the normal workflow.
     */
    public function assertNotAssigned(array $orderIds): void
    {
        $existing = DB::table('picklist_items as existing_items')
            ->join('picklists as existing_picklists', 'existing_picklists.id', '=', 'existing_items.picklist_id')
            ->join('sales_orders as existing_orders', 'existing_orders.id', '=', 'existing_items.order_id')
            ->whereIn('existing_items.order_id', $orderIds)
            ->select([
                'existing_orders.salesorder_no',
                'existing_picklists.picklist_no',
                'existing_picklists.status',
            ])
            ->distinct()
            ->orderBy('existing_orders.salesorder_no')
            ->orderBy('existing_picklists.picklist_no')
            ->get();

        if ($existing->isEmpty()) {
            return;
        }

        $details = $existing
            ->map(static fn ($row): string => "{$row->salesorder_no} ({$row->picklist_no}, {$row->status})")
            ->implode(', ');

        throw new OutboundValidationException(
            "Order sudah terdaftar di picklist: {$details}. Revert/hapus asosiasi picklist lama terlebih dahulu."
        );
    }
}
