<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Inbound\Models\Inbound;
use Modules\Inventory\Models\Inventory;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Product\Models\ProductVariant;
use Modules\Purchase\Models\PurchaseOrderItem;

class RebuildAverageCost extends Command
{
    protected $signature = 'inventory:rebuild-avg-cost
                            {--dry-run : Hitung tapi jangan tulis ke DB}
                            {--item= : Batasi ke item_id (UUID) tertentu}';

    protected $description = 'Rebuild avg_cost di table inventories dengan replay moving average dari inventory_movements.';

    private const RECEIVE_SOURCES = ['ADJUSTMENT', 'PUTAWAY_IN', 'TRANSFER_IN', 'TRANSIT_IN', 'SPLIT_IN'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyItem = $this->option('item');

        $this->info('Mulai rebuild avg_cost' . ($dryRun ? ' (DRY-RUN)' : '') . '...');

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

    private function deriveCostFromPO(InventoryMovement $mv): float
    {

        $inbound = Inbound::where('transaction_number', $mv->transaction_number)->first();
        if ($inbound && $inbound->source_id) {
            $poItem = PurchaseOrderItem::where('purchase_order_id', $inbound->source_id)
                ->where('item_id', $mv->item_id)
                ->first();
            if ($poItem) {
                $cost = (float) $poItem->landed_cost_per_unit;
                if ($cost > 0) {
                    return $cost;
                }
            }
        }

        $poItem = PurchaseOrderItem::query()
            ->where('item_id', $mv->item_id)
            ->where('received_qty', '>', 0)
            ->whereHas('purchaseOrder', function ($q) use ($mv) {
                $q->whereDate('order_date', '<=', $mv->transaction_date);
            })
            ->orderByDesc('updated_at')
            ->first();
        if ($poItem) {
            $cost = (float) $poItem->landed_cost_per_unit;
            if ($cost > 0) {
                return $cost;
            }
        }

        $variant = ProductVariant::find($mv->item_id);
        if ($variant && (float) $variant->buy_price > 0) {
            return (float) $variant->buy_price;
        }

        return 0.0;
    }
}
