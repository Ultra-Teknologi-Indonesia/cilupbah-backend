<?php

namespace Modules\Outbound\Services;

use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Events\OrderNeedsBuyerConfirmation;
use Modules\Sales\Services\SalesInvoiceService;
use Modules\Sales\Services\SalesOrderService as OrderService;

class OrderReleaseService
{
    public function __construct(
        protected OrderService $orderService,
        protected SalesInvoiceService $invoiceService,
        protected PicklistInvoiceStockService $picklistInvoiceStockService,
    ) {}

    public function releaseIfComplete(Picklist $picklist, string $orderId): bool
    {
        $items = PicklistItem::with('order')
            ->where('picklist_id', $picklist->id)
            ->where('order_id', $orderId)
            ->get();

        if ($items->isEmpty()) {
            return false;
        }

        $order = $items->first()->order;

        if (! $order || ! in_array($order->status, ['reserved', 'picked'], true)) {
            return false;
        }

        $isResolved = fn ($item) => $item->isResolved();

        if (! $items->every($isResolved)) {
            return false;
        }

        $shortItems = $items->filter(fn ($item) => in_array(
            $item->item_status,
            [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED],
            true,
        ));

        foreach ($items->groupBy('order_item_id') as $orderItemId => $parts) {
            $shortPart = $parts->first(fn ($it) => in_array(
                $it->item_status,
                [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED],
                true,
            ));

            if ($shortPart) {
                DB::table('sales_order_items')
                    ->where('id', $orderItemId)
                    ->update([
                        'fulfillment_status' => $shortPart->item_status,
                        'short_qty' => $parts->sum(fn ($it) => (int) ($it->failed_qty ?? 0)),
                        'updated_at' => now(),
                    ]);

                continue;
            }

            if ($parts->every(fn ($it) => $it->isResolved())) {
                DB::table('sales_order_items')
                    ->where('id', $orderItemId)
                    ->update([
                        'fulfillment_status' => 'PICKED',
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($shortItems->isNotEmpty()) {
            DB::table('sales_orders')
                ->where('id', $order->id)
                ->update([
                    'status' => 'AWAITING_BUYER_CONFIRMATION',
                    'awaiting_confirmation_at' => now(),
                    'updated_at' => now(),
                ]);

            OrderNeedsBuyerConfirmation::dispatch(
                (string) $order->id,
                (string) $picklist->id,
                $shortItems->map(fn ($it) => [
                    'item_id' => (string) $it->id,
                    'sku' => $it->sku,
                    'failed_qty' => (int) ($it->failed_qty ?? 0),
                    'item_status' => (string) $it->item_status,
                ])->values()->all(),
            );

            return true;
        }

        if ($order->status === 'reserved') {
            $order = $this->orderService->updateOrder($order, ['status' => 'picked'], $picklist->picker);
        }

        $actorId = $picklist->picker_id ? (string) $picklist->picker_id : (auth()->id() ? (string) auth()->id() : 'system');
        $invoice = $this->invoiceService->createFromOrder([
            'order_id' => (string) $order->id,
            'location_id' => (string) $order->location_id,
            'created_by' => $actorId,
        ]);

        $this->picklistInvoiceStockService->post($picklist, $order, $invoice, $actorId);

        return true;
    }
}
