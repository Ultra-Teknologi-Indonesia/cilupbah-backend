<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMovement;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\PacklistItem;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\StockService;

class PacklistStockService
{
    public function __construct(
        protected StockService $stockService,
    ) {}

    public function post(Packlist $packlist, Collection $packItems, string $actor): void
    {
        $order = SalesOrder::query()
            ->select(['id', 'salesorder_no', 'channel_order_no', 'no_ref'])
            ->find($packlist->order_id);
        $reference = $this->orderReference($order);

        foreach ($packItems as $packItem) {
            $qtyToPack = (int) $packItem->qty_packed;
            if ($qtyToPack <= 0) {
                continue;
            }

            $allocations = $this->allocationsFor($packlist, $packItem);
            $allocatedQty = (int) $allocations->sum('qty');

            if ($allocatedQty < $qtyToPack) {
                throw new OutboundValidationException(
                    "Qty {$packItem->sku} yang sudah dipacking ({$qtyToPack}) melebihi qty yang sudah dipick ({$allocatedQty})."
                );
            }

            $remaining = $qtyToPack;
            foreach ($allocations as $allocation) {
                if ($remaining <= 0) {
                    break;
                }

                $covered = min($remaining, (int) $allocation->qty);
                $committed = min($covered, (int) $allocation->physical_committed_qty);
                $toPost = $covered - $committed;

                if ($toPost > 0) {
                    $bin = $allocation->bin;
                    if (! $bin) {
                        throw new OutboundValidationException(
                            "Rak untuk SKU {$packItem->sku} tidak ditemukan, packing dibatalkan agar stok tetap konsisten."
                        );
                    }

                    try {
                        $this->stockService->consumeFromBin(
                            (string) $packItem->sku,
                            (string) $packItem->item_id,
                            (string) $packlist->location_id,
                            (string) $allocation->bin_id,
                            $toPost,
                            (string) $packlist->packlist_no,
                            'PACKING',
                            $actor ?: 'system',
                            null,
                            $reference,
                        );
                    } catch (InsufficientStockException $exception) {
                        throw new OutboundValidationException($exception->getMessage(), 422, $exception);
                    }

                    $allocation->physical_committed_qty = (int) $allocation->physical_committed_qty + $toPost;
                    $allocation->save();
                }

                $remaining -= $covered;
            }

            if ($remaining > 0) {
                throw new OutboundValidationException(
                    "Stok untuk SKU {$packItem->sku} belum memiliki alokasi pick yang lengkap."
                );
            }
        }
    }

    public function reverse(Packlist $packlist, string $actor): void
    {
        $movements = InventoryMovement::query()
            ->where('transaction_number', $packlist->packlist_no)
            ->where('source', 'PACKING')
            ->lockForUpdate()
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        $order = SalesOrder::query()
            ->select(['id', 'salesorder_no', 'channel_order_no', 'no_ref'])
            ->find($packlist->order_id);

        foreach ($movements as $movement) {
            $qty = abs((int) $movement->qty);
            if ($qty <= 0) {
                continue;
            }

            $this->stockService->restoreToBin(
                (string) ($movement->item_id ? $this->skuForItem($movement->item_id) : 'item'),
                (string) $movement->item_id,
                (string) $movement->location_id,
                $movement->bin_id,
                $qty,
                $packlist->packlist_no.'-REVERT',
                'PACKING_REVERSAL',
                $actor ?: 'system',
                $this->orderReference($order),
            );

            $this->undoPhysicalCommitment($packlist, $movement, $qty);
        }
    }

    private function allocationsFor(Packlist $packlist, PacklistItem $packItem): Collection
    {
        return PicklistItemAllocation::query()
            ->select('picklist_item_allocations.*')
            ->join('picklist_items as pi', 'pi.id', '=', 'picklist_item_allocations.picklist_item_id')
            ->where('pi.order_id', $packlist->order_id)
            ->where('pi.order_item_id', $packItem->order_item_id)
            ->where('pi.item_id', $packItem->item_id)
            ->with('bin')
            ->orderBy('picklist_item_allocations.picked_at')
            ->orderBy('picklist_item_allocations.id')
            ->lockForUpdate()
            ->get();
    }

    private function undoPhysicalCommitment(Packlist $packlist, InventoryMovement $movement, int $qty): void
    {
        $allocations = PicklistItemAllocation::query()
            ->select('picklist_item_allocations.*')
            ->join('picklist_items as pi', 'pi.id', '=', 'picklist_item_allocations.picklist_item_id')
            ->where('pi.order_id', $packlist->order_id)
            ->where('pi.item_id', $movement->item_id)
            ->where('picklist_item_allocations.bin_id', $movement->bin_id)
            ->where('picklist_item_allocations.physical_committed_qty', '>', 0)
            ->orderByDesc('picklist_item_allocations.picked_at')
            ->orderByDesc('picklist_item_allocations.id')
            ->lockForUpdate()
            ->get();

        $remaining = $qty;
        foreach ($allocations as $allocation) {
            if ($remaining <= 0) {
                break;
            }

            $reverted = min($remaining, (int) $allocation->physical_committed_qty);
            $allocation->physical_committed_qty -= $reverted;
            $allocation->save();
            $remaining -= $reverted;
        }

        if ($remaining > 0) {
            throw new OutboundValidationException(
                "Riwayat komitmen stok untuk packlist {$packlist->packlist_no} tidak lengkap, pembatalan dihentikan agar saldo tetap aman."
            );
        }
    }

    private function orderReference(?SalesOrder $order): ?string
    {
        if (! $order) {
            return null;
        }

        foreach (['channel_order_no', 'no_ref', 'salesorder_no'] as $column) {
            $value = trim((string) ($order->{$column} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function skuForItem(string $itemId): string
    {
        return (string) (DB::table('product_variants')->where('id', $itemId)->value('sku') ?: "item:{$itemId}");
    }
}
