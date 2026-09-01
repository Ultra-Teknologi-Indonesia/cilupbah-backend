<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Inventory\Support\InventoryMovementSourceMap;

final class InventoryMovementReversalVisibilityService
{
    private const PAIRS_TABLE = 'inventory_movement_reversal_pairs';

    public function pairReversal(InventoryMovement $reversal): ?array
    {
        if (! in_array($reversal->source, InventoryMovementSourceMap::CHRONOLOGY_NETTABLE_REVERSAL_SOURCES, true)) {
            return null;
        }

        $qty = (int) $reversal->qty;
        if ($qty === 0 || $reversal->id === null) {
            return null;
        }

        if ($this->reversalAlreadyPaired($reversal->id)) {
            return null;
        }

        $original = InventoryMovement::query()
            ->from('inventory_movements as candidate')
            ->where('candidate.item_id', $reversal->item_id)
            ->where('candidate.location_id', $reversal->location_id)
            ->where(function ($query) use ($reversal): void {
                if ($reversal->bin_id === null) {
                    $query->whereNull('candidate.bin_id');
                } else {
                    $query->where('candidate.bin_id', $reversal->bin_id);
                }
            })
            ->where('candidate.qty', -$qty)
            ->whereNotIn('candidate.source', InventoryMovementSourceMap::REVERSAL_SOURCES)
            ->where(function ($query) use ($reversal): void {
                $query
                    ->where('candidate.transaction_date', '<', $reversal->transaction_date)
                    ->orWhere(function ($sameTime) use ($reversal): void {
                        $sameTime
                            ->where('candidate.transaction_date', $reversal->transaction_date)
                            ->where('candidate.id', '<', $reversal->id);
                    });
            })
            ->whereNotExists(function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from(self::PAIRS_TABLE.' as existing_pair')
                    ->whereColumn('existing_pair.original_movement_id', 'candidate.id');
            })
            ->orderByDesc('candidate.transaction_date')
            ->orderByDesc('candidate.id')
            ->lockForUpdate()
            ->first(['candidate.*']);

        if ($original === null) {
            return null;
        }

        $pair = [
            'id' => (string) Str::uuid(),
            'original_movement_id' => $original->id,
            'reversal_movement_id' => $reversal->id,
            'created_at' => now(),
        ];

        DB::table(self::PAIRS_TABLE)->insertOrIgnore($pair);

        return $pair;
    }

    public function pairExists(string $originalId, string $reversalId): bool
    {
        return DB::table(self::PAIRS_TABLE)
            ->where('original_movement_id', $originalId)
            ->where('reversal_movement_id', $reversalId)
            ->exists();
    }

    private function reversalAlreadyPaired(string $reversalId): bool
    {
        return DB::table(self::PAIRS_TABLE)
            ->where('reversal_movement_id', $reversalId)
            ->exists();
    }
}
