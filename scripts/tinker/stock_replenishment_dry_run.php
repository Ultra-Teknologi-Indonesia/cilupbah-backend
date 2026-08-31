return (function (): array {
    $db = app('db');
    $schema = $db->getSchemaBuilder();
    $requiredTables = [
        'locations',
        'inventories',
        'location_bins',
        'sales_orders',
        'sales_order_items',
        'stock_replenishment_requests',
        'stock_replenishment_request_items',
        'inventory_transfers',
    ];

    foreach ($requiredTables as $table) {
        if (! $schema->hasTable($table)) {
            throw new RuntimeException("Tabel {$table} belum tersedia.");
        }
    }

    $mainId = $db->table('locations')
        ->where('is_warehouse', true)
        ->where('is_small_warehouse', false)
        ->value('id');
    $smallId = $db->table('locations')
        ->where('is_warehouse', true)
        ->where('is_small_warehouse', true)
        ->value('id');

    if (! $mainId || ! $smallId) {
        throw new RuntimeException('Gudang Pusat / Gudang Kecil belum tersedia.');
    }

    $activeOrderStatuses = [
        'pending',
        'reserved',
        'UNPAID',
        'AWAITING_BUYER_CONFIRMATION',
    ];

    $demand = $db->table('sales_order_items as soi')
        ->join('sales_orders as so', 'so.id', '=', 'soi.order_id')
        ->where('so.location_id', $smallId)
        ->whereIn('so.status', $activeOrderStatuses)
        ->whereNotNull('soi.item_id')
        ->groupBy('soi.item_id', 'soi.sku')
        ->select('soi.item_id', 'soi.sku')
        ->selectRaw('SUM(soi.qty_in_base) as needed')
        ->get()
        ->keyBy('item_id');

    $availability = $db->table('inventories as i')
        ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
        ->where('i.location_id', $smallId)
        ->groupBy('i.item_id')
        ->select('i.item_id')
        ->selectRaw('COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.is_inbound = false THEN i.on_hand ELSE 0 END), 0) as on_hand')
        ->selectRaw('COALESCE(SUM(i.on_order), 0) as on_order')
        ->get()
        ->keyBy('item_id');

    $acceptedCoverage = $db->table('stock_replenishment_request_items as ri')
        ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
        ->where('r.status', 'ACCEPTED')
        ->where('r.to_location_id', $smallId)
        ->groupBy('ri.item_id')
        ->select('ri.item_id')
        ->selectRaw('SUM(ri.qty) as in_flight')
        ->get()
        ->keyBy('item_id');

    $monitorRows = $demand->map(function ($row, $itemId) use ($availability, $acceptedCoverage): ?array {
        $onHand = (int) ($availability[$itemId]->on_hand ?? 0);
        $onOrder = (int) ($availability[$itemId]->on_order ?? 0);
        $inFlight = (int) ($acceptedCoverage[$itemId]->in_flight ?? 0);
        $needed = (int) $row->needed;
        $shortage = max(0, $needed - max(0, $onHand) - $inFlight);

        if ($onHand > 0) {
            return null;
        }

        return [
            'item_id' => (string) $itemId,
            'sku' => (string) $row->sku,
            'needed' => $needed,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'in_flight' => $inFlight,
            'shortage' => $shortage,
        ];
    })->filter()->values();

    $pendingRequests = $db->table('stock_replenishment_requests')
        ->where('status', 'PENDING')
        ->where('from_location_id', $mainId)
        ->where('to_location_id', $smallId)
        ->select('id', 'from_location_id', 'to_location_id')
        ->orderBy('id')
        ->get();

    $pendingItemIds = $db->table('stock_replenishment_request_items as ri')
        ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
        ->where('r.status', 'PENDING')
        ->where('r.from_location_id', $mainId)
        ->where('r.to_location_id', $smallId)
        ->pluck('ri.item_id')
        ->map(fn ($id): string => (string) $id)
        ->unique()
        ->values();

    $actionableRows = $monitorRows
        ->filter(fn (array $row): bool => $row['shortage'] > 0)
        ->values();
    $monitorItemIds = $actionableRows->pluck('item_id');
    $alreadySelectedCount = $monitorItemIds->intersect($pendingItemIds)->count();
    $newMonitorItemsWaitingForUserCount = $monitorItemIds->diff($pendingItemIds)->count();
    $duplicatePendingRouteCount = $pendingRequests
        ->groupBy(fn ($request): string => "{$request->from_location_id}:{$request->to_location_id}")
        ->filter(fn ($requests): bool => $requests->count() > 1)
        ->count();

    $pendingBefore = $db->table('stock_replenishment_requests')
        ->where('status', 'PENDING')
        ->count();
    $transfersBefore = $db->table('inventory_transfers')->count();

    $pendingAfter = $db->table('stock_replenishment_requests')
        ->where('status', 'PENDING')
        ->count();
    $transfersAfter = $db->table('inventory_transfers')->count();

    if ($pendingBefore !== $pendingAfter || $transfersBefore !== $transfersAfter) {
        throw new RuntimeException('DRY-RUN gagal: jumlah data berubah saat simulasi.');
    }

    return [
        'ok' => true,
        'dry_run' => true,
        'uses_new_deployed_code' => false,
        'mutations' => false,
        'warehouse_main_found' => true,
        'warehouse_small_found' => true,
        'dipesan_namun_habis_count' => $monitorRows->count(),
        'actionable_shortage_count' => $actionableRows->count(),
        'already_selected_in_pending_count' => $alreadySelectedCount,
        'new_items_waiting_for_monitor_selection_count' => $newMonitorItemsWaitingForUserCount,
        'pending_request_count_before' => $pendingBefore,
        'pending_request_count_after' => $pendingAfter,
        'transfer_count_before' => $transfersBefore,
        'transfer_count_after' => $transfersAfter,
        'duplicate_pending_route_count' => $duplicatePendingRouteCount,
        'sample_shortages' => $actionableRows->take(20)->all(),
        'message' => 'Simulasi standalone berhasil. Tidak ada INSERT, UPDATE, DELETE, request baru, atau transfer yang dibuat.',
    ];
})();
