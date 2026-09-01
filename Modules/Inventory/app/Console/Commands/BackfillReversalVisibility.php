<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Services\InventoryMovementReversalVisibilityService;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Inventory\Support\KronologiReversalNetter;

final class BackfillReversalVisibility extends Command
{
    protected $signature = 'inventory:backfill-reversal-visibility
                            {--apply : Simpan pasangan visibility. Tanpa flag ini hanya dry-run.}
                            {--item= : Batasi ke item_id tertentu.}
                            {--location= : Batasi ke location_id tertentu.}';

    protected $description = 'Audit dan tandai pasangan reversal otomatis agar tidak tampil di kronologi operasional tanpa mengubah stok atau ledger.';

    public function handle(): int
    {
        $cellQuery = InventoryMovement::query()
            ->whereIn('source', InventoryMovementSourceMap::CHRONOLOGY_NETTABLE_REVERSAL_SOURCES)
            ->where('qty', '!=', 0)
            ->select(['item_id', 'location_id'])
            ->distinct();

        if ($this->option('item')) {
            $cellQuery->where('item_id', $this->option('item'));
        }

        if ($this->option('location')) {
            $cellQuery->where('location_id', $this->option('location'));
        }

        $cells = $cellQuery->get();
        if ($cells->isEmpty()) {
            $this->info('Tidak ada reversal otomatis yang perlu diaudit.');

            return self::SUCCESS;
        }

        $visibility = app(InventoryMovementReversalVisibilityService::class);
        $pairCount = 0;
        $alreadyPaired = 0;
        $unmatched = 0;

        foreach ($cells as $cell) {
            $rows = InventoryMovement::query()
                ->where('item_id', $cell->item_id)
                ->where('location_id', $cell->location_id)
                ->where('qty', '!=', 0)
                ->orderBy('transaction_date')
                ->orderBy('id')
                ->get(['id', 'item_id', 'location_id', 'bin_id', 'source', 'qty']);

            $pairs = KronologiReversalNetter::pairs($rows);
            $reversalIds = $rows
                ->whereIn('source', InventoryMovementSourceMap::CHRONOLOGY_NETTABLE_REVERSAL_SOURCES)
                ->pluck('id');

            $pairCount += count($pairs);
            $unmatched += max(0, $reversalIds->count() - count($pairs));

            if (! $this->option('apply')) {
                continue;
            }

            DB::transaction(function () use ($pairs, $visibility, &$alreadyPaired): void {
                foreach ($pairs as $pair) {
                    if ($visibility->pairExists($pair['original_id'], $pair['reversal_id'])) {
                        $alreadyPaired++;

                        continue;
                    }

                    DB::table('inventory_movement_reversal_pairs')->insertOrIgnore([
                        'id' => (string) Str::uuid(),
                        'original_movement_id' => $pair['original_id'],
                        'reversal_movement_id' => $pair['reversal_id'],
                        'created_at' => now(),
                    ]);
                }
            });
        }

        $this->line('Mode: '.($this->option('apply') ? 'APPLY' : 'DRY-RUN'));
        $this->line('Sel item+lokasi diaudit: '.$cells->count());
        $this->line('Pasangan reversal terdeteksi: '.$pairCount);
        $this->line('Reversal tanpa pasangan: '.$unmatched);
        $this->line('Pasangan yang sudah tersimpan: '.$alreadyPaired);

        if (! $this->option('apply')) {
            $this->warn('Dry-run: tidak ada insert, update, delete, atau perubahan stok.');
        } else {
            $this->info('Backfill visibility selesai. Stok dan ledger tidak diubah.');
        }

        return self::SUCCESS;
    }
}
