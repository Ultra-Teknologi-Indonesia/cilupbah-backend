<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Inventory\Support\KronologiReversalNetter;

class PurgeReversalMovements extends Command
{
    protected $signature = 'inventory:purge-reversals
                            {--apply : Hapus permanen. Tanpa flag ini hanya melaporkan (dry-run).}';

    protected $description = 'Tandai pasangan koreksi/revert lama agar tersembunyi dari kronologi operasional tanpa menghapus ledger atau mengubah stok.';

    public function handle(): int
    {
        $pairs = InventoryMovement::query()
            ->whereIn('source', InventoryMovementSourceMap::CHRONOLOGY_NETTABLE_REVERSAL_SOURCES)
            ->where('qty', '!=', 0)
            ->get(['item_id', 'location_id'])
            ->unique(fn ($r) => $r->item_id.'|'.$r->location_id)
            ->values();

        if ($pairs->isEmpty()) {
            $this->info('Tidak ada gerakan reversal. Kronologi sudah bersih.');

            return self::SUCCESS;
        }

        $rows = InventoryMovement::query()
            ->where('qty', '!=', 0)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($qq) use ($p) {
                        $qq->where('item_id', $p->item_id)
                            ->where('location_id', $p->location_id);
                    });
                }
            })
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'item_id', 'location_id', 'bin_id', 'source', 'qty']);

        $matchedPairs = KronologiReversalNetter::pairs($rows);

        if (empty($matchedPairs)) {
            $this->info('Tidak ada pasangan reversal-asal yang cocok untuk disembunyikan.');

            return self::SUCCESS;
        }

        $this->line('Pasangan yang akan disembunyikan: '.count($matchedPairs)
            .' (dari '.$pairs->count().' sel item+lokasi tersentuh reversal).');

        if (! $this->option('apply')) {
            $this->warn('Dry-run. Jalankan ulang dengan --apply untuk menyimpan visibility pair.');

            return self::SUCCESS;
        }

        $saved = 0;
        foreach (array_chunk($matchedPairs, 500) as $chunk) {
            foreach ($chunk as $pair) {
                $saved += DB::table('inventory_movement_reversal_pairs')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'original_movement_id' => $pair['original_id'],
                    'reversal_movement_id' => $pair['reversal_id'],
                    'created_at' => now(),
                ]);
            }
        }

        $this->info("Selesai. {$saved} pasangan ditandai; ledger dan stok tidak diubah.");

        return self::SUCCESS;
    }
}
