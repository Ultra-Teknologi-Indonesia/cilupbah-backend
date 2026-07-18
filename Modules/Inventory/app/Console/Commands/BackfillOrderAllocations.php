<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\StockService;
use Modules\Warehouse\Models\Location;

class BackfillOrderAllocations extends Command
{
    protected $signature = 'orders:backfill-allocations
        {--dry-run : Hanya tampilkan pesanan yang akan diproses, tidak menulis ledger}';

    protected $description = 'Buat baris ledger ORDER_RESERVE untuk pesanan berstatus reserved yang belum punya jejak alokasi. Idempotent, tidak mengubah on_order.';

    public function handle(StockService $stockService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $kecilId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');

        $orders = SalesOrder::with('items')
            ->where('status', 'reserved')
            ->get();

        $this->info(sprintf('%d pesanan berstatus reserved ditemukan.', $orders->count()));

        $rows = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            $locationId = $this->resolveLocationId($order, $kecilId);

            if (! $locationId) {
                $this->warn(sprintf('  · %s dilewati — lokasi tidak dapat ditentukan.', $order->salesorder_no));
                $skipped++;
                continue;
            }

            foreach ($order->items as $item) {
                if (! $item->item_id || (int) $item->qty_in_base <= 0) {
                    continue;
                }

                if ($dryRun) {
                    $this->line(sprintf('  · %s — item %s x%d @%s', $order->salesorder_no, $item->item_id, (int) $item->qty_in_base, $locationId));
                    $rows++;
                    continue;
                }

                $rows += $stockService->recordExistingReservation(
                    $item->sku ?? "item:{$item->item_id}",
                    $item->item_id,
                    $locationId,
                    (int) $item->qty_in_base,
                    $order->salesorder_no,
                );
            }
        }

        $this->info(sprintf('%s %d baris ledger ORDER_RESERVE.%s',
            $dryRun ? '[dry-run] akan membuat' : 'Dibuat',
            $rows,
            $skipped > 0 ? sprintf(' %d pesanan dilewati.', $skipped) : '',
        ));

        return self::SUCCESS;
    }

    private function resolveLocationId(SalesOrder $order, ?string $kecilId): ?string
    {
        $isManual = in_array($order->source, [null, '', 'manual'], true);

        if ($isManual && $order->location_id) {
            return $order->location_id;
        }

        return $kecilId ?: $order->location_id;
    }
}
