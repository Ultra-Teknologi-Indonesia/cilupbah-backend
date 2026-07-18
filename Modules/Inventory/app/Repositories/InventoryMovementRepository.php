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
            ->whereIn('source', InventoryMovementSourceMap::RESERVED_SOURCES)
            ->exists();
    }

    public function getByTransactionNumber(string $transactionNumber): Collection
    {
        return InventoryMovement::where('transaction_number', $transactionNumber)
            ->with(['product', 'location', 'bin'])
            ->get();
    }

    public function create(array $data): InventoryMovement
    {
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

    public function getHistoryPaginated(int $limit = 10)
    {
        $view = strtolower((string) request('view', 'all'));
        $baseQuery = InventoryMovement::query()->where('qty', '!=', 0);

        if ($view === 'clean') {
            $baseQuery->whereNotIn('source', InventoryMovementSourceMap::CLEAN_HIDDEN_SOURCES);
        } elseif ($view === 'attention') {
            $baseQuery->whereIn('source', InventoryMovementSourceMap::INVOICE_SOURCES);
        }

        if (trim((string) request('search', '')) !== '') {
            $baseQuery->leftJoin('product_variants', 'product_variants.id', '=', 'inventory_movements.item_id')
                ->leftJoin('products', 'products.id', '=', 'product_variants.product_id');
        }

        $reservedList = "'" . implode("','", InventoryMovementSourceMap::RESERVED_SOURCES) . "'";

        $qb = \Spatie\QueryBuilder\QueryBuilder::for($baseQuery)
            ->select('inventory_movements.*')
            ->selectRaw("SUM(qty) OVER (PARTITION BY item_id, location_id, (CASE WHEN source IN ($reservedList) THEN 1 ELSE 0 END) ORDER BY transaction_date, inventory_movements.id) AS total_balance")
            ->selectRaw('(SELECT COALESCE(so.channel_order_no, so.no_ref) FROM sales_orders so WHERE so.salesorder_no = inventory_movements.transaction_number LIMIT 1) AS ref_no')
            ->selectRaw('(SELECT so.customer_name FROM sales_orders so WHERE so.salesorder_no = inventory_movements.transaction_number LIMIT 1) AS ref_note')
            ->with(['product:id,sku,product_id', 'location:id,location_name', 'bin:id,bin_final_code'])
            ->allowedSearch('product_variants.sku', 'products.name')
            ->allowedFilters(
                \Spatie\QueryBuilder\AllowedFilter::exact('item_id'),
                \Spatie\QueryBuilder\AllowedFilter::exact('location_id'),
                \Spatie\QueryBuilder\AllowedFilter::callback('source', function ($query, $value) {
                    $sources = is_array($value) ? $value : explode(',', (string) $value);
                    $sources = array_values(array_filter(array_map('trim', $sources)));
                    if (count($sources) > 0) {
                        $query->whereIn('source', $sources);
                    }
                }),
                \Spatie\QueryBuilder\AllowedFilter::callback('direction', function ($query, $value) {
                    $dir = strtolower((string) $value);
                    if ($dir === 'in' || $dir === 'masuk') {
                        $query->where('qty', '>', 0);
                    } elseif ($dir === 'out' || $dir === 'keluar') {
                        $query->where('qty', '<', 0);
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
