<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Facades\DB;
use Modules\Outbound\Exceptions\OutboundValidationException;

final class PicklistOrderGuard
{

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
