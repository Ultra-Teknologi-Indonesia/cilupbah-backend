<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Collection;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItemAllocation;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Models\SalesInvoice;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\StockService;

/**
 * Posts the physical stock movement at Finish Pick.
 *
 * A completed allocation is the source of truth for the rack and quantity that
 * actually left shelving. physical_committed_qty makes a queue retry safe: a
 * previously posted allocation is never consumed for a second time.
 */
class PicklistInvoiceStockService
{
    public function __construct(
        protected StockService $stockService,
    ) {}

    public function post(Picklist $picklist, SalesOrder $order, SalesInvoice $invoice, string $actor): void
    {
        $allocations = $this->allocationsFor($picklist, $order);

        if ($allocations->isEmpty()) {
            throw new OutboundValidationException(
                "Pesanan {$order->salesorder_no} tidak memiliki alokasi rak hasil pick. Faktur tidak dapat memotong stok tanpa rak asal."
            );
        }

        $reference = $this->orderReference($order);
        $transactionDate = $picklist->completed_at ?? now();

        foreach ($allocations as $allocation) {
            $pickItem = $allocation->picklistItem;
            if (! $pickItem || ! $pickItem->item_id) {
                throw new OutboundValidationException(
                    "Alokasi pick {$allocation->id} tidak memiliki SKU yang valid untuk diposting ke faktur."
                );
            }

            $allocatedQty = (int) $allocation->qty;
            $committedQty = (int) $allocation->physical_committed_qty;
            if ($committedQty > $allocatedQty) {
                throw new OutboundValidationException(
                    "Komitmen stok untuk SKU {$pickItem->sku} melebihi qty hasil pick. Proses dihentikan agar stok tidak terpotong ganda."
                );
            }

            $qtyToPost = $allocatedQty - $committedQty;
            if ($qtyToPost <= 0) {
                continue;
            }

            if (! $allocation->bin) {
                throw new OutboundValidationException(
                    "Rak asal untuk SKU {$pickItem->sku} tidak ditemukan. Faktur tidak dapat memotong stok tanpa rak asal."
                );
            }

            try {
                $movement = $this->stockService->consumeFromBin(
                    (string) $pickItem->sku,
                    (string) $pickItem->item_id,
                    (string) $picklist->location_id,
                    (string) $allocation->bin_id,
                    $qtyToPost,
                    (string) $invoice->invoice_number,
                    'INVOICE',
                    $actor ?: 'system',
                    $transactionDate,
                    $reference,
                );
            } catch (InsufficientStockException $exception) {
                throw new OutboundValidationException($exception->getMessage(), 422, $exception);
            }

            $allocation->forceFill([
                'physical_committed_qty' => $allocatedQty,
                'movement_id' => $movement?->id,
            ])->save();
        }
    }

    private function allocationsFor(Picklist $picklist, SalesOrder $order): Collection
    {
        return PicklistItemAllocation::query()
            ->select('picklist_item_allocations.*')
            ->join('picklist_items as pi', 'pi.id', '=', 'picklist_item_allocations.picklist_item_id')
            ->where('pi.picklist_id', $picklist->id)
            ->where('pi.order_id', $order->id)
            ->with(['bin', 'picklistItem'])
            ->orderBy('picklist_item_allocations.picked_at')
            ->orderBy('picklist_item_allocations.id')
            ->lockForUpdate()
            ->get();
    }

    private function orderReference(SalesOrder $order): ?string
    {
        foreach (['channel_order_no', 'no_ref', 'salesorder_no'] as $column) {
            $value = trim((string) ($order->{$column} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
