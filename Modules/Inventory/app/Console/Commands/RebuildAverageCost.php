<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Purchase\Models\PurchaseOrderItem;

class RebuildAverageCost extends Command
{
    protected $signature = 'inventory:rebuild-avg-cost
                            {--dry-run : Hitung tapi jangan tulis ke DB}
                            {--item= : Batasi ke item_id (UUID) tertentu}';

    protected $description = 'Rebuild avg_cost di table inventories dengan replay moving average dari inventory_movements.';

    /** Source values yang dianggap "receive masuk" (qty positif menambah stok). */
    private const RECEIVE_SOURCES = ['ADJUSTMENT', 'PUTAWAY_IN', 'TRANSFER_IN', 'TRANSIT_IN', 'SPLIT_IN'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyItem = $this->option('item');

        $this->info('Mulai rebuild avg_cost' . ($dryRun ? ' (DRY-RUN)' : '') . '...');

        // Diagnostic: print distinct sources untuk visibility.
        $sources = InventoryMovement::query()->distinct()->pluck('source');
        $this->line('Distinct movement sources: ' . $sources->implode(', '));

        $query = Inventory::query();
        if ($onlyItem) {
            $query->where('item_id', $onlyItem);
        }

        $inventories = $query->get();
        $updated = 0;
        $skipped = 0;
        $noCostRows = 0;

        foreach ($inventories as $inv) {
            $movements = InventoryMovement::where('item_id', $inv->item_id)
                ->where('location_id', $inv->location_id)
                ->where(function ($q) use ($inv) {
                    $q->where('bin_id', $inv->bin_id);
                    if (is_null($inv->bin_id)) {
                        $q->orWhereNull('bin_id');
                    }
                })
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get();

            if ($movements->isEmpty()) {
                $skipped++;
                continue;
            }

            $runningQty = 0.0;
            $runningAvg = 0.0;

            foreach ($movements as $mv) {
                $qty = (float) $mv->qty;

                // Hanya gunakan IN-movements untuk update avg.
                if ($qty <= 0 || ! in_array($mv->source, self::RECEIVE_SOURCES, true)) {
                    $runningQty = max(0.0, $runningQty + $qty);
                    continue;
                }

                $cost = (float) ($mv->cost_per_unit ?? 0);
                if ($cost <= 0) {
                    $cost = $this->deriveCostFromPO($mv);
                }

                if ($cost <= 0) {
                    $noCostRows++;
                    $runningQty += $qty;
                    continue;
                }

                $total = $runningQty + $qty;
                $runningAvg = $total > 0
                    ? (($runningQty * $runningAvg) + ($qty * $cost)) / $total
                    : $cost;
                $runningQty = $total;
            }

            if ($runningAvg <= 0) {
                $skipped++;
                continue;
            }

            $newAvg = round($runningAvg, 2);
            $oldAvg = (float) ($inv->avg_cost ?? 0);

            if (abs($newAvg - $oldAvg) < 0.005) {
                continue;
            }

            $this->line(sprintf(
                '  item=%s loc=%s bin=%s : %s → %s',
                $inv->item_id,
                $inv->location_id,
                $inv->bin_id ?? '-',
                number_format($oldAvg, 2),
                number_format($newAvg, 2),
            ));

            if (! $dryRun) {
                $inv->avg_cost = $newAvg;
                $inv->save();
            }

            $updated++;
        }

        $this->info("Selesai. Updated={$updated}, Skipped={$skipped}, Rows_tanpa_cost={$noCostRows}.");

        return self::SUCCESS;
    }

    /**
     * Coba derive cost dari PurchaseOrderItem berdasarkan transaction_number.
     * Best-effort: bila tidak ketemu, return 0.
     */
    private function deriveCostFromPO(InventoryMovement $mv): float
    {
        // Inbound transaction_number biasanya = reference number PO atau prefix INB-.
        // Pendekatan: cari PO item dengan item_id sama yang punya PO ter-receive paling dekat.
        $poItem = PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function ($q) use ($mv) {
                $q->where('order_number', $mv->transaction_number)
                  ->orWhere('reference_number', $mv->transaction_number);
            })
            ->where('item_id', $mv->item_id)
            ->first();

        if (! $poItem) {
            return 0.0;
        }

        return (float) $poItem->landed_cost_per_unit;
    }
}
