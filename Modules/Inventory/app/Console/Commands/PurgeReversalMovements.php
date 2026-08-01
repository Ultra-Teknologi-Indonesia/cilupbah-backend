<?php

namespace Modules\Inventory\Console\Commands;

use Illuminate\Console\Command;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\InventoryMovementSourceMap;
use Modules\Inventory\Support\KronologiReversalNetter;

class PurgeReversalMovements extends Command
{
    protected $signature = 'inventory:purge-reversals
                            {--apply : Hapus permanen. Tanpa flag ini hanya melaporkan (dry-run).}';

    protected $description = 'Bersihkan jejak koreksi/revert lama dari kronologi: hapus baris reversal beserta gerakan asal yang dibatalkannya (pasangan net-nol). Tidak menyentuh on_hand.';

    public function handle(): int
    {
        $pairs = InventoryMovement::query()
            ->whereIn('source', InventoryMovementSourceMap::UNRECORDED_REVERSAL_SOURCES)
            ->where('qty', '!=', 0)
            ->get(['item_id', 'location_id'])
            ->unique(fn ($r) => $r->item_id . '|' . $r->location_id)
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

        $hiddenIds = KronologiReversalNetter::hiddenIds($rows);

        if (empty($hiddenIds)) {
            $this->info('Tidak ada pasangan reversal-asal yang cocok untuk dihapus.');
            return self::SUCCESS;
        }

        $this->line('Baris yang akan dihapus: ' . count($hiddenIds)
            . ' (dari ' . $pairs->count() . ' sel item+lokasi tersentuh reversal).');

        if (! $this->option('apply')) {
            $this->warn('Dry-run. Jalankan ulang dengan --apply untuk menghapus permanen.');
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_chunk($hiddenIds, 500) as $chunk) {
            $deleted += InventoryMovement::whereIn('id', $chunk)->delete();
        }

        $this->info("Selesai. {$deleted} baris kronologi dihapus.");
        return self::SUCCESS;
    }
}
