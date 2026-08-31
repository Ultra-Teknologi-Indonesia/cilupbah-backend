return (function (): array {
    $db = app('db');
    $schema = $db->getSchemaBuilder();

    foreach ([
        'locations',
        'inventories',
        'location_bins',
        'sales_orders',
        'sales_order_items',
        'stock_replenishment_requests',
        'stock_replenishment_request_items',
        'inventory_transfers',
    ] as $table) {
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

    $activeStatuses = [
        'pending',
        'reserved',
        'UNPAID',
        'AWAITING_BUYER_CONFIRMATION',
    ];

    $demand = $db->table('sales_order_items as soi')
        ->join('sales_orders as so', 'so.id', '=', 'soi.order_id')
        ->where('so.location_id', $smallId)
        ->whereIn('so.status', $activeStatuses)
        ->whereNotNull('soi.item_id')
        ->groupBy('soi.item_id', 'soi.sku')
        ->select('soi.item_id', 'soi.sku')
        ->selectRaw('SUM(soi.qty_in_base) as needed')
        ->get()
        ->keyBy('item_id');

    $pendingRequests = $db->table('stock_replenishment_requests as r')
        ->leftJoin('locations as lf', 'lf.id', '=', 'r.from_location_id')
        ->leftJoin('locations as lt', 'lt.id', '=', 'r.to_location_id')
        ->where('r.status', 'PENDING')
        ->select([
            'r.id',
            'r.from_location_id',
            'r.to_location_id',
            'r.transfer_out_id',
            'r.requested_at',
            'lf.location_name as from_location_name',
            'lt.location_name as to_location_name',
        ])
        ->orderBy('r.requested_at')
        ->orderBy('r.id')
        ->get();

    $requestItems = $db->table('stock_replenishment_request_items')
        ->whereIn('request_id', $pendingRequests->pluck('id')->all())
        ->select('request_id', 'item_id', 'sku', 'qty')
        ->orderBy('request_id')
        ->get()
        ->groupBy('request_id');

    $itemIds = $requestItems
        ->flatten(1)
        ->pluck('item_id')
        ->merge($demand->keys())
        ->filter()
        ->unique()
        ->values()
        ->all();

    $availability = $itemIds === []
        ? collect()
        : $db->table('inventories as i')
            ->leftJoin('location_bins as b', 'b.id', '=', 'i.bin_id')
            ->where('i.location_id', $smallId)
            ->whereIn('i.item_id', $itemIds)
            ->groupBy('i.item_id')
            ->select('i.item_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN b.id IS NOT NULL AND b.is_inbound = false THEN i.on_hand ELSE 0 END), 0) as on_hand')
            ->selectRaw('COALESCE(SUM(i.on_order), 0) as on_order')
            ->get()
            ->keyBy('item_id');

    $acceptedCoverage = $itemIds === []
        ? collect()
        : $db->table('stock_replenishment_request_items as ri')
            ->join('stock_replenishment_requests as r', 'r.id', '=', 'ri.request_id')
            ->where('r.status', 'ACCEPTED')
            ->where('r.to_location_id', $smallId)
            ->whereIn('ri.item_id', $itemIds)
            ->groupBy('ri.item_id')
            ->select('ri.item_id')
            ->selectRaw('SUM(ri.qty) as in_flight')
            ->get()
            ->keyBy('item_id');

    $shortageByItem = $demand->mapWithKeys(function ($row, $itemId) use ($availability, $acceptedCoverage): array {
        $onHand = (int) ($availability[$itemId]->on_hand ?? 0);
        $onOrder = (int) ($availability[$itemId]->on_order ?? 0);
        $inFlight = (int) ($acceptedCoverage[$itemId]->in_flight ?? 0);
        $needed = (int) $row->needed;

        return [$itemId => [
            'sku' => (string) $row->sku,
            'needed' => $needed,
            'on_hand' => $onHand,
            'on_order' => $onOrder,
            'in_flight' => $inFlight,
            'shortage' => max(0, $needed - max(0, $onHand) - $inFlight),
        ]];
    });

    $routes = $pendingRequests->groupBy(
        fn ($request): string => "{$request->from_location_id}:{$request->to_location_id}"
    );
    $duplicateRoutes = $routes->filter(fn ($requests): bool => $requests->count() > 1);

    $requestItemMap = $pendingRequests->mapWithKeys(function ($request) use ($requestItems): array {
        return [$request->id => $requestItems->get($request->id, collect())];
    });

    $safeDelete = collect();
    $cancelRecommended = collect();
    $mergeBeforeDelete = collect();
    $keepActive = collect();

    foreach ($pendingRequests as $request) {
        $items = $requestItemMap->get($request->id, collect());
        $routeKey = "{$request->from_location_id}:{$request->to_location_id}";
        $routeRequests = $routes->get($routeKey, collect());
        $keeper = $routeRequests->first();
        $hasTransfer = ! empty($request->transfer_out_id);
        $isStandardRoute = $request->from_location_id === $mainId
            && $request->to_location_id === $smallId;
        $activeItems = $items->filter(
            fn ($item): bool => (int) ($shortageByItem[$item->item_id]['shortage'] ?? 0) > 0
        );

        $base = [
            'request_id' => (string) $request->id,
            'requested_at' => (string) $request->requested_at,
            'route' => sprintf(
                '%s (%s) → %s (%s)',
                $request->from_location_name ?? '-',
                $request->from_location_id,
                $request->to_location_name ?? '-',
                $request->to_location_id,
            ),
            'item_count' => $items->count(),
            'total_qty' => (int) $items->sum('qty'),
            'transfer_out_id' => $request->transfer_out_id,
        ];

        if ($hasTransfer) {
            $keepActive->push($base + ['classification' => 'PROTECTED_HAS_TRANSFER']);
            continue;
        }

        if (! $isStandardRoute) {
            $keepActive->push($base + [
                'classification' => 'REVIEW_OTHER_ROUTE',
                'recommendation' => 'Jangan hapus otomatis; rute ini bukan Gudang Pusat → Gudang Kecil.',
            ]);
            continue;
        }

        if ($items->isEmpty()) {
            $safeDelete->push($base + [
                'classification' => 'DELETE_SAFE_EMPTY',
                'recommendation' => 'Boleh dihapus; lebih baik CANCELLED untuk menjaga audit trail.',
            ]);
            continue;
        }

        if ($activeItems->isEmpty()) {
            $cancelRecommended->push($base + [
                'classification' => 'CANCEL_RECOMMENDED_STALE',
                'recommendation' => 'Kebutuhan sudah tidak aktif; batalkan, jangan hard delete.',
            ]);
            continue;
        }

        if ($routeRequests->count() > 1 && $keeper && $keeper->id !== $request->id) {
            $keeperItems = $requestItemMap->get($keeper->id, collect())->keyBy('item_id');
            $canDeleteDuplicate = $items->every(
                fn ($item): bool => isset($keeperItems[$item->item_id])
                    && (int) $item->qty <= (int) $keeperItems[$item->item_id]->qty
            );

            $duplicateData = $base + [
                'keeper_request_id' => (string) $keeper->id,
                'classification' => $canDeleteDuplicate
                    ? 'DELETE_SAFE_DUPLICATE_SUBSET'
                    : 'MERGE_BEFORE_DELETE',
                'recommendation' => $canDeleteDuplicate
                    ? 'Boleh dihapus setelah keeper dikonfirmasi.'
                    : 'Jangan hapus langsung; gabungkan item unik ke keeper terlebih dahulu.',
            ];

            ($canDeleteDuplicate ? $safeDelete : $mergeBeforeDelete)->push($duplicateData);
            continue;
        }

        $keepActive->push($base + ['classification' => 'KEEP_ACTIVE']);
    }

    $pendingBefore = $db->table('stock_replenishment_requests')
        ->where('status', 'PENDING')
        ->count();
    $transfersBefore = $db->table('inventory_transfers')->count();
    $pendingAfter = $db->table('stock_replenishment_requests')
        ->where('status', 'PENDING')
        ->count();
    $transfersAfter = $db->table('inventory_transfers')->count();

    if ($pendingBefore !== $pendingAfter || $transfersBefore !== $transfersAfter) {
        throw new RuntimeException('DRY-RUN gagal: jumlah data berubah selama pemeriksaan.');
    }

    return [
        'ok' => true,
        'dry_run' => true,
        'mutations' => false,
        'pending_total' => $pendingRequests->count(),
        'duplicate_route_count' => $duplicateRoutes->count(),
        'safe_delete_count' => $safeDelete->count(),
        'cancel_recommended_count' => $cancelRecommended->count(),
        'merge_before_delete_count' => $mergeBeforeDelete->count(),
        'keep_active_or_protected_count' => $keepActive->count(),
        'pending_before' => $pendingBefore,
        'pending_after' => $pendingAfter,
        'transfers_before' => $transfersBefore,
        'transfers_after' => $transfersAfter,
        'safe_delete_candidates' => $safeDelete->values()->all(),
        'cancel_recommended_candidates' => $cancelRecommended->values()->all(),
        'merge_before_delete_candidates' => $mergeBeforeDelete->values()->all(),
        'message' => 'Audit dry-run selesai. Kandidat ditampilkan saja; tidak ada data yang dihapus atau diubah.',
    ];
})();
