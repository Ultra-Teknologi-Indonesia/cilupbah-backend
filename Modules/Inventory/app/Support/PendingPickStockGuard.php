<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Exceptions\UserFacingException;
use Illuminate\Support\Facades\DB;
use Modules\Product\Models\ProductVariant;
use Modules\Warehouse\Models\LocationBin;

final class PendingPickStockGuard
{
    public function pendingQty(string $itemId, string $locationId, ?string $binId): int
    {
        $query = DB::table('picklist_item_allocations as pia')
            ->join('picklist_items as pi', 'pi.id', '=', 'pia.picklist_item_id')
            ->join('picklists as pl', 'pl.id', '=', 'pi.picklist_id')
            ->where('pi.item_id', $itemId)
            ->where('pl.location_id', $locationId)
            ->whereNotIn('pl.status', ['FAILED', 'CANCELLED'])
            ->whereRaw('GREATEST(COALESCE(pia.qty, 0) - COALESCE(pia.physical_committed_qty, 0), 0) > 0');

        if ($binId === null) {
            $query->whereNull('pia.bin_id');
        } else {
            $query->where('pia.bin_id', $binId);
        }

        return (int) $query->sum(DB::raw(
            'GREATEST(COALESCE(pia.qty, 0) - COALESCE(pia.physical_committed_qty, 0), 0)'
        ));
    }

    public function assertResultIsSafe(
        string $itemId,
        string $locationId,
        ?string $binId,
        int $resultingOnHand,
        string $operation,
    ): void {
        $pendingQty = $this->pendingQty($itemId, $locationId, $binId);

        if ($pendingQty <= 0 || $resultingOnHand >= $pendingQty) {
            return;
        }

        $sku = (string) (ProductVariant::query()->whereKey($itemId)->value('sku') ?: $itemId);
        $rack = $binId === null
            ? null
            : (string) (LocationBin::query()->whereKey($binId)->value('bin_final_code') ?: $binId);
        $subject = $rack ? " SKU {$sku} di rak {$rack}" : " SKU {$sku}";

        throw new UserFacingException(
            title: 'Stok sedang dipakai picking',
            message: "{$operation} dibatalkan karena{$subject} masih memiliki {$pendingQty} pcs yang sudah dipick tetapi belum finish pick. Selesaikan atau gagalkan picking terlebih dahulu.",
            status: 422,
            errors: [
                'sku' => $sku,
                'rack_code' => $rack,
                'pending_pick_qty' => $pendingQty,
                'resulting_on_hand' => $resultingOnHand,
            ],
        );
    }
}
