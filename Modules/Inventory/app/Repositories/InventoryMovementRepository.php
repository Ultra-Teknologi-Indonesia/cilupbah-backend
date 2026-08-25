<?php

namespace Modules\Inventory\Repositories;

use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Database\Eloquent\Collection;

class InventoryMovementRepository
{
    public function getByItem(string $itemId, string $locationId): Collection
    {
        return InventoryMovement::where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->orderByDesc('transaction_date')
            ->with(['bin'])
            ->get();
    }

    public function reservationLedgerExists(string $transactionNumber, string $itemId, string $locationId): bool
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->whereIn('source', InventoryMovementSourceMap::ORDER_LEDGER_SOURCES)
            ->exists();
    }

    public function movementExists(string $transactionNumber, string $itemId, string $locationId, ?string $binId, string $source): bool
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('source', $source)
            ->when(
                $binId === null,
                fn ($q) => $q->whereNull('bin_id'),
                fn ($q) => $q->where('bin_id', $binId),
            )
            ->exists();
    }

    public function findMovement(string $transactionNumber, string $itemId, string $locationId, ?string $binId, string $source): ?InventoryMovement
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->where('source', $source)
            ->when(
                $binId === null,
                fn ($q) => $q->whereNull('bin_id'),
                fn ($q) => $q->where('bin_id', $binId),
            )
            ->first();
    }

    public function getByTransactionNumber(string $transactionNumber): Collection
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->with(['product', 'location', 'bin'])
            ->get();
    }

    public function create(array $data): InventoryMovement
    {

        if (in_array($data['source'] ?? null, InventoryMovementSourceMap::UNRECORDED_REVERSAL_SOURCES, true)) {
            $cancelled = $this->cancelMatchedOriginal($data);
            if ($cancelled !== null) {
                return $cancelled;
            }
        }

        $movement = InventoryMovement::create($data);

        $newBalance = $data['balance'] ?? null;
        $qty = (int) ($data['qty'] ?? 0);
        $previousBalance = $newBalance !== null ? ((int) $newBalance) - $qty : null;

        if ($newBalance !== null && (int) $newBalance < 0 && $previousBalance !== null && $previousBalance >= 0) {
            try {
                app(NotificationDispatcher::class)->toPermission('view-laporan-stok-minus', [
                    'type' => 'stock_negative_recorded',
                    'title' => 'Stok tercatat minus',
                    'message' => "Stok item turun ke {$newBalance} akibat gerakan {$movement->source}.",
                    'data' => [
                        'movement_id' => $movement->id,
                        'item_id' => $data['item_id'] ?? null,
                        'location_id' => $data['location_id'] ?? null,
                        'source' => $data['source'] ?? null,
                        'balance' => (int) $newBalance,
                        'qty' => $qty,
                        'link' => '/dashboard/laporan/stok-minus',
                    ],
                ]);
            } catch (\Throwable $e) {
                \Log::warning('Notifikasi stok minus gagal: ' . $e->getMessage(), [
                    'movement_id' => $movement->id ?? null,
                ]);
            }
        }

        return $movement;
    }

    private function cancelMatchedOriginal(array $data): ?InventoryMovement
    {
        $qty = (int) ($data['qty'] ?? 0);
        if ($qty === 0) {
            return null;
        }

        $query = InventoryMovement::query()
            ->where('item_id', $data['item_id'] ?? null)
            ->where('location_id', $data['location_id'] ?? null)
            ->where('qty', -$qty)
            ->whereNotIn('source', InventoryMovementSourceMap::REVERSAL_SOURCES);

        if (! empty($data['bin_id'])) {
            $query->where('bin_id', $data['bin_id']);
        } else {
            $query->whereNull('bin_id');
        }

        $original = $query->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->first();

        if ($original === null) {
            return null;
        }

        $original->delete();

        return $original;
    }

    public function getHistoryPaginated(int $limit = 10)
    {
        $view = strtolower((string) request('view', 'all'));
        $baseQuery = InventoryMovement::query()->where('qty', '!=', 0);

        $baseQuery->whereNotExists(function ($q) {
            $q->selectRaw('1')
                ->from('location_bins')
                ->whereColumn('location_bins.id', 'inventory_movements.bin_id')
                ->where('location_bins.is_inbound', true);
        });

        if ($view === 'clean') {
            $baseQuery->whereNotIn('source', InventoryMovementSourceMap::CLEAN_HIDDEN_SOURCES);
        } elseif ($view === 'attention') {
            $baseQuery->whereIn('source', InventoryMovementSourceMap::INVOICE_SOURCES);
        } else {
            $drill = strtolower((string) request('filter.drill', request('drill', '')));
            if ($drill !== 'allocation') {
                $baseQuery->where(function ($q) {
                    $q->where('source', '!=', 'ORDER_RELEASE')
                        ->orWhereExists(function ($soQ) {
                            $soQ->selectRaw('1')
                                ->from('sales_orders as so_cancel')
                                ->whereColumn('so_cancel.salesorder_no', 'inventory_movements.transaction_number')
                                ->where(function ($st) {
                                    $st->where('so_cancel.status', 'cancelled')
                                        ->orWhere('so_cancel.is_canceled', true);
                                });
                        });
                });

                $baseQuery->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('sales_orders as so_invoiced')
                        ->join('sales_invoices as si', 'si.order_id', '=', 'so_invoiced.id')
                        ->whereRaw("inventory_movements.source = 'ORDER_RESERVE'")
                        ->whereColumn('so_invoiced.salesorder_no', 'inventory_movements.transaction_number')
                        ->where('si.status', '!=', 'CANCELLED');
                });

                $baseQuery->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('inventory_movements as im_done')
                        ->whereRaw("inventory_movements.source = 'ORDER_RESERVE'")
                        ->whereColumn('im_done.item_id', 'inventory_movements.item_id')
                        ->whereIn('im_done.source', ['INVOICE', 'ORDER_COMPLETE_OUT', 'ORDER_SHIP'])
                        ->where(function ($sub) {
                            $sub->whereColumn('im_done.transaction_number', 'inventory_movements.transaction_number')
                                ->orWhereExists(function ($soQ) {
                                    $soQ->selectRaw('1')
                                        ->from('sales_orders as so')
                                        ->whereColumn('so.salesorder_no', 'inventory_movements.transaction_number')
                                        ->where(function ($invQ) {
                                            $invQ->whereColumn('im_done.transaction_number', 'so.salesorder_no')
                                                ->orWhereRaw("im_done.transaction_number = CONCAT('INV-', so.salesorder_no)");
                                        });
                                });
                        });
                });

                $baseQuery->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('sales_orders as so_shipped')
                        ->whereRaw("inventory_movements.source = 'ORDER_RESERVE'")
                        ->whereColumn('so_shipped.salesorder_no', 'inventory_movements.transaction_number')
                        ->whereIn('so_shipped.status', ['shipped', 'delivered', 'completed']);
                });

                $baseQuery->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('sales_orders as so_cancel_rsv')
                        ->whereRaw("inventory_movements.source = 'ORDER_RESERVE'")
                        ->whereColumn('so_cancel_rsv.salesorder_no', 'inventory_movements.transaction_number')
                        ->where(function ($st) {
                            $st->where('so_cancel_rsv.status', 'cancelled')
                                ->orWhere('so_cancel_rsv.is_canceled', true);
                        });
                });

                $baseQuery->whereNotExists(function ($q) {
                    $q->selectRaw('1')
                        ->from('inventory_movements as im_done')
                        ->whereRaw("inventory_movements.source = 'ORDER_RESERVE'")
                        ->whereColumn('im_done.item_id', 'inventory_movements.item_id')
                        ->where('im_done.source', 'PICKING')
                        ->whereExists(function ($pickQ) {
                            $pickQ->selectRaw('1')
                                ->from('picklist_items as pi')
                                ->join('picklists as p', 'p.id', '=', 'pi.picklist_id')
                                ->join('sales_orders as so', 'so.id', '=', 'pi.order_id')
                                ->whereColumn('so.salesorder_no', 'inventory_movements.transaction_number')
                                ->whereRaw("p.picklist_no = regexp_replace(im_done.transaction_number, '-(KOREKSI|HAPUS)$', '')");
                        });
                });
            }
        }

        if (trim((string) request('search', '')) !== '') {
            $baseQuery->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_movements.item_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id');
        }

        $deductList = "'" . implode("','", InventoryMovementSourceMap::ORDER_DEDUCT_SOURCES) . "'";
        $restoreList = "'" . implode("','", InventoryMovementSourceMap::ORDER_RESTORE_SOURCES) . "'";
        $effectiveQtySql = "CASE WHEN source IN ($deductList) THEN -ABS(qty) WHEN source IN ($restoreList) THEN ABS(qty) ELSE qty END";
        $reservedList = "'" . implode("','", InventoryMovementSourceMap::ALLOCATION_PARTITION_SOURCES) . "'";

        $pickOrderScope = "FROM picklist_items pi"
            . " JOIN picklists p ON p.id = pi.picklist_id"
            . " JOIN sales_orders so ON so.id = pi.order_id"
            . " WHERE p.picklist_no = regexp_replace(inventory_movements.transaction_number, '-(KOREKSI|HAPUS)$', '')"
            . " AND pi.item_id = inventory_movements.item_id";
        $isPick = "inventory_movements.source IN ('PICKING', 'PICKING_REVERSAL')";
        $pickOrder = fn (string $column) =>
            "(CASE WHEN {$isPick} THEN (SELECT {$column} {$pickOrderScope} ORDER BY so.salesorder_no LIMIT 1) END)";

        $hasLocationFilter = ! empty(request('filter.location_id', request('location_id')));
        $balancePartition = $hasLocationFilter
            ? 'item_id, location_id'
            : 'item_id';

        $qb = \Spatie\QueryBuilder\QueryBuilder::for($baseQuery)
            ->select('inventory_movements.*')
            ->selectRaw("SUM({$effectiveQtySql}) OVER (PARTITION BY {$balancePartition} ORDER BY transaction_date, inventory_movements.id) AS total_balance")
            ->selectRaw(
                '(SELECT EXISTS(SELECT 1 FROM sales_invoices si '
                . 'JOIN picklist_items pi ON pi.order_id = si.order_id '
                . 'JOIN picklists p ON p.id = pi.picklist_id '
                . "WHERE p.picklist_no = regexp_replace(inventory_movements.transaction_number, '-(KOREKSI|HAPUS)$', '') "
                . "AND pi.item_id = inventory_movements.item_id AND si.status != 'CANCELLED'"
                . ')) AS has_invoice'
            )
            ->selectRaw(
                '(SELECT COALESCE(CASE '
                . " WHEN inb.transaction_number LIKE 'TRFI%' OR inb.type = 'TRANSIT_IN' OR inb.source_type IN ('transfer', 'inventory_transfer') THEN 'transfer'"
                . " WHEN inb.type = 'SALES_RETURN' OR inb.source_type IN ('sales_return', 'return') THEN 'sales_return'"
                . " WHEN inb.type = 'PURCHASE_ORDER' OR inb.source_type IN ('purchase_order', 'purchase') THEN 'purchase_order'"
                . ' ELSE NULL END, NULLIF(inb.source_type, \'\'), NULLIF(inb.type, \'\'), NULLIF(put.source_type, \'\'))'
                . ' FROM putaways put LEFT JOIN inbounds inb ON inb.id = put.source_id WHERE put.putaway_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') LIMIT 1) AS putaway_source_type'
            )
            ->selectRaw($pickOrder('so.salesorder_no') . ' AS pick_order_no')
            ->selectRaw("(CASE WHEN {$isPick} THEN (SELECT COUNT(DISTINCT pi.order_id) {$pickOrderScope}) END) AS pick_order_count")
            ->selectRaw(
                'COALESCE('
                . '(SELECT COALESCE(so.channel_order_no, so.no_ref) FROM sales_orders so WHERE so.salesorder_no = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT it.transfer_number FROM inventory_transfers it WHERE it.receive_number = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT pb.ref_no FROM purchase_bills pb WHERE pb.bill_number = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT po.ref_no FROM purchase_orders po WHERE po.po_number = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT COALESCE(inb.transaction_number, inb.reference_number) FROM putaways put JOIN inbounds inb ON inb.id = put.source_id WHERE put.putaway_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') LIMIT 1), '
                . $pickOrder('COALESCE(so.channel_order_no, so.no_ref)')
                . ') AS ref_no'
            )
            ->selectRaw(
                'COALESCE('
                . '(SELECT NULLIF(TRIM(CONCAT_WS(\' | \', NULLIF(TRIM(so.buyer_message), \'\'), NULLIF(TRIM(COALESCE(so.seller_note, so.note)), \'\'))), \'\') FROM sales_orders so WHERE so.salesorder_no = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT NULLIF(TRIM(COALESCE(sai.notes, sa.notes)), \'\') FROM stock_adjustments sa'
                . '  LEFT JOIN stock_adjustment_items sai ON sai.stock_adjustment_id = sa.id AND sai.item_id = inventory_movements.item_id'
                . '  WHERE sa.adjustment_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') AND sa.deleted_at IS NULL LIMIT 1), '
                . '(SELECT NULLIF(TRIM(it.notes), \'\') FROM inventory_transfers it'
                . '  WHERE (it.transfer_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\')'
                . '     OR it.receive_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\')) LIMIT 1), '
                . '(SELECT CONCAT(\'Transfer: \', sl.location_name, \' → \', dl.location_name) FROM inventory_transfers it'
                . '  LEFT JOIN locations sl ON sl.id = it.source_location_id'
                . '  LEFT JOIN locations dl ON dl.id = it.destination_location_id'
                . '  WHERE (it.transfer_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\')'
                . '     OR it.receive_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\')) LIMIT 1), '
                . '(SELECT NULLIF(TRIM(COALESCE(bti.notes, bt.notes)), \'\') FROM bin_transfers bt'
                . '  LEFT JOIN bin_transfer_items bti ON bti.bin_transfer_id = bt.id AND bti.item_id = inventory_movements.item_id'
                . '  WHERE bt.transfer_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') AND bt.deleted_at IS NULL LIMIT 1), '
                . '(SELECT CONCAT(\'Pindah Rak: \', sb.bin_final_code, \' → \', db.bin_final_code) FROM bin_transfers bt'
                . '  LEFT JOIN bin_transfer_items bti ON bti.bin_transfer_id = bt.id AND bti.item_id = inventory_movements.item_id'
                . '  LEFT JOIN location_bins sb ON sb.id = bti.source_bin_id'
                . '  LEFT JOIN location_bins db ON db.id = bti.destination_bin_id'
                . '  WHERE bt.transfer_number = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') AND bt.deleted_at IS NULL LIMIT 1), '
                . '(SELECT NULLIF(TRIM(so_op.notes), \'\') FROM stock_opnames so_op'
                . '  WHERE so_op.opname_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') AND so_op.deleted_at IS NULL LIMIT 1), '
                . '(SELECT NULLIF(TRIM(pb.notes), \'\') FROM purchase_bills pb WHERE pb.bill_number = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT NULLIF(TRIM(po.notes), \'\') FROM purchase_orders po WHERE po.po_number = inventory_movements.transaction_number LIMIT 1), '
                . '(SELECT NULLIF(TRIM(po_inb.notes), \'\') FROM putaways put JOIN inbounds inb ON inb.id = put.source_id LEFT JOIN purchase_orders po_inb ON (po_inb.id = inb.source_id OR po_inb.po_number = inb.reference_number) WHERE put.putaway_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') LIMIT 1), '
                . '(SELECT NULLIF(TRIM(CASE WHEN inb.notes LIKE \'Auto-generated dari PO%\' THEN \'\' ELSE inb.notes END), \'\') FROM putaways put JOIN inbounds inb ON inb.id = put.source_id WHERE put.putaway_no = regexp_replace(inventory_movements.transaction_number, \'-(BATAL|KOREKSI|HAPUS)$\', \'\') LIMIT 1), '
                . '(SELECT NULLIF(TRIM(COALESCE(sr.notes, sr.reason)), \'\') FROM sales_returns sr WHERE sr.return_number = inventory_movements.transaction_number LIMIT 1), '
                . $pickOrder('NULLIF(TRIM(CONCAT_WS(\' | \', NULLIF(TRIM(so.buyer_message), \'\'), NULLIF(TRIM(COALESCE(so.seller_note, so.note)), \'\'))), \'\')')
                . ') AS ref_note'
            )
            ->selectRaw(
                'COALESCE('
                . '(SELECT cs.shop_name FROM sales_orders so'
                . '  LEFT JOIN channel_shops cs ON cs.shop_id = so.channel_shop_id'
                . '  WHERE so.salesorder_no = inventory_movements.transaction_number LIMIT 1), '
                . "(CASE WHEN {$isPick} THEN (SELECT cs2.shop_name"
                . '  FROM picklist_items pi'
                . '  JOIN picklists p ON p.id = pi.picklist_id'
                . '  JOIN sales_orders so ON so.id = pi.order_id'
                . '  LEFT JOIN channel_shops cs2 ON cs2.shop_id = so.channel_shop_id'
                . "  WHERE p.picklist_no = regexp_replace(inventory_movements.transaction_number, '-(KOREKSI|HAPUS)$', '')"
                . '  AND pi.item_id = inventory_movements.item_id'
                . '  ORDER BY so.salesorder_no LIMIT 1) END)'
                . ') AS store_name'
            )
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->allowedSearch('product_variants.sku', 'products.name')
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('item_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::callback('source', function ($query, $value) {
                    $tokens = is_array($value) ? $value : explode(',', (string) $value);

                    $sources = InventoryMovementSourceMap::expandFilterTokens($tokens);
                    if (count($sources) > 0) {
                        $query->where(function ($q) use ($sources, $tokens) {
                            $isTransfer = in_array('TRANSFER', $tokens, true)
                                || in_array('TRANSFER_IN', $tokens, true)
                                || in_array('TRANSFER_OUT', $tokens, true);
                            $isTagihan = in_array('TAGIHAN', $tokens, true)
                                || in_array('PURCHASE', $tokens, true)
                                || in_array('BILL', $tokens, true)
                                || in_array('PUTAWAY_IN', $tokens, true);
                            $isReturn = in_array('RETUR_PENJUALAN', $tokens, true)
                                || in_array('SALES_RETURN', $tokens, true);

                            $transferPutawayScope = function ($putQ) {
                                $putQ->selectRaw('1')
                                    ->from('putaways as p')
                                    ->leftJoin('inbounds as inb', 'inb.id', '=', 'p.source_id')
                                    ->whereRaw("p.putaway_no = regexp_replace(inventory_movements.transaction_number, '-(BATAL|KOREKSI|HAPUS)$', '')")
                                    ->where(function ($w) {
                                        $w->where('inb.source_type', 'transfer')
                                            ->orWhere('inb.source_type', 'inventory_transfer')
                                            ->orWhere('inb.type', 'TRANSIT_IN')
                                            ->orWhere('inb.transaction_number', 'LIKE', 'TRFI%')
                                            ->orWhere('inb.reference_number', 'LIKE', 'TRFI%')
                                            ->orWhere('p.source_type', 'TRANSFER');
                                    });
                            };

                            $returnPutawayScope = function ($putQ) {
                                $putQ->selectRaw('1')
                                    ->from('putaways as p')
                                    ->leftJoin('inbounds as inb', 'inb.id', '=', 'p.source_id')
                                    ->whereRaw("p.putaway_no = regexp_replace(inventory_movements.transaction_number, '-(BATAL|KOREKSI|HAPUS)$', '')")
                                    ->where(function ($w) {
                                        $w->where('inb.source_type', 'sales_return')
                                            ->orWhere('inb.source_type', 'return')
                                            ->orWhere('inb.type', 'SALES_RETURN')
                                            ->orWhere('inb.transaction_number', 'LIKE', 'RET%')
                                            ->orWhere('inb.reference_number', 'LIKE', 'RET%')
                                            ->orWhere('p.source_type', 'SALES_RETURN')
                                            ->orWhere('p.source_type', 'RETURN');
                                    });
                            };

                            if ($isTagihan && ! $isTransfer && ! $isReturn) {
                                $q->where(function ($sub) use ($sources, $transferPutawayScope, $returnPutawayScope) {
                                    $sub->whereIn('source', array_diff($sources, ['PUTAWAY_IN', 'PUTAWAY_OUT', 'PUTAWAY_REVERSAL']))
                                        ->orWhere(function ($putSub) use ($transferPutawayScope, $returnPutawayScope) {
                                            $putSub->whereIn('source', ['PUTAWAY_IN', 'PUTAWAY_OUT', 'PUTAWAY_REVERSAL'])
                                                ->whereNotExists($transferPutawayScope)
                                                ->whereNotExists($returnPutawayScope);
                                        });
                                });
                            } else {
                                $q->whereIn('source', $sources);
                            }

                            if ($isTransfer) {
                                $q->orWhere(function ($sub) use ($transferPutawayScope) {
                                    $sub->whereIn('source', ['PUTAWAY_IN', 'PUTAWAY_OUT', 'PUTAWAY_REVERSAL'])
                                        ->whereExists($transferPutawayScope);
                                });
                            }

                            if ($isReturn) {
                                $q->orWhere(function ($sub) use ($returnPutawayScope) {
                                    $sub->whereIn('source', ['PUTAWAY_IN', 'PUTAWAY_OUT', 'PUTAWAY_REVERSAL'])
                                        ->whereExists($returnPutawayScope);
                                });
                            }
                        });
                    }
                }),
                \Spatie\QueryBuilder\AllowedFilter::callback('direction', function ($query, $value) use ($effectiveQtySql) {
                    $dir = strtolower((string) $value);
                    if ($dir === 'in' || $dir === 'masuk') {
                        $query->whereRaw("({$effectiveQtySql}) > 0");
                    } elseif ($dir === 'out' || $dir === 'keluar') {
                        $query->whereRaw("({$effectiveQtySql}) < 0");
                    }
                }),

                \Spatie\QueryBuilder\AllowedFilter::callback('drill', function ($query, $value) {
                    $value = (string) $value;

                    if ($value === 'order_active') {
                        $query->where('inventory_movements.source', 'ORDER_RESERVE')
                            ->whereExists(function ($sub) {
                                $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                                    ->from('sales_orders')
                                    ->whereColumn('sales_orders.salesorder_no', 'inventory_movements.transaction_number')
                                    ->where('sales_orders.status', 'reserved');
                            });

                        return;
                    }

                    $sources = InventoryMovementSourceMap::DRILL_SCOPES[$value] ?? null;
                    if ($sources !== null) {
                        $query->whereIn('source', $sources);
                    }
                }),
                \Spatie\QueryBuilder\AllowedFilter::exact('transaction_number'),
                \Spatie\QueryBuilder\AllowedFilter::callback('store_id', function ($query, $value) {
                    if ($value === null || $value === '') return;
                    $query->whereExists(function ($sub) use ($value) {
                        $sub->select(\Illuminate\Support\Facades\DB::raw(1))
                            ->from('sales_orders')
                            ->whereColumn('sales_orders.salesorder_no', 'inventory_movements.transaction_number')
                            ->where('sales_orders.channel_shop_id', $value);
                    });
                }),
                \Spatie\QueryBuilder\AllowedFilter::callback('date_from', function ($query, $value) {
                    $query->whereDate('transaction_date', '>=', $value);
                }),
                \Spatie\QueryBuilder\AllowedFilter::callback('date_to', function ($query, $value) {
                    $query->whereDate('transaction_date', '<=', $value);
                })
            )
            ->allowedSorts('transaction_date', 'created_at')
            ->defaultSort('-transaction_date');

        return $qb->paginate(request('per_page', $limit))
            ->appends(request()->query());
    }
}
