<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inbound\Services\InboundService;
use Modules\Inventory\Models\InventoryTransfer;

class BackfillTransitInbounds extends Command
{
    protected $signature = 'transfers:backfill-transit-inbounds
        {--dry-run : Hanya tampilkan transfer yang akan diproses, tidak membuat inbound}';

    protected $description = 'Buat inbound TRANSIT_IN (DRAFT) untuk transfer IN_TRANSIT/CHECKING yang belum punya dokumen penerimaan.';

    public function handle(InboundService $inboundService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $transfers = InventoryTransfer::with('items')
            ->whereIn('status', [
                InventoryTransfer::STATUS_IN_TRANSIT,
                InventoryTransfer::STATUS_CHECKING,
            ])
            ->get();

        $created = 0;

        foreach ($transfers as $transfer) {
            $items = $transfer->items
                ->map(fn ($item) => [
                    'item_id'      => $item->item_id,
                    'expected_qty' => $item->qty,
                ])->values()->toArray();

            if (empty($items)) {
                continue;
            }

            $this->line(sprintf('  · %s (%s) — %d item', $transfer->transfer_number, $transfer->status, count($items)));

            if ($dryRun) {
                continue;
            }

            $inboundService->createPendingTransit([
                'location_id'      => $transfer->destination_location_id,
                'reference_number' => $transfer->transfer_number,
                'source_id'        => $transfer->id,
                'expected_date'    => $transfer->shipped_at ?? $transfer->created_at,
                'created_by'       => $transfer->created_by,
                'items'            => $items,
            ]);

            $created++;
        }

        if ($dryRun) {
            $this->info(sprintf('[DRY-RUN] %d transfer akan diproses.', $transfers->count()));
            return self::SUCCESS;
        }

        $this->info(sprintf('Selesai. %d inbound TRANSIT_IN (DRAFT) dibuat/idempotent dari %d transfer.', $created, $transfers->count()));

        return self::SUCCESS;
    }
}
