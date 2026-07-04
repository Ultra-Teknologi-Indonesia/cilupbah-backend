<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Sales\Events\OrderNeedsBuyerConfirmation;
use Modules\Sales\Services\SalesOrderService as OrderService;

class ProcessPicklistCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $picklistId,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(OrderService $orderService): void
    {
        $picklist = Picklist::with('items.order', 'items.orderItem')->find($this->picklistId);

        if (!$picklist || $picklist->status !== Picklist::STATUS_COMPLETED) {
            return;
        }

        $itemsByOrder = $picklist->items->groupBy('order_id');

        DB::transaction(function () use ($picklist, $itemsByOrder, $orderService) {
            foreach ($itemsByOrder as $orderId => $items) {
                $order = $items->first()->order;
                if (!$order) {
                    continue;
                }

                $shortItems = $items->filter(fn ($it) => in_array(
                    $it->item_status,
                    [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED],
                    true,
                ));

                // Update sales_order_items.fulfillment_status per item.
                foreach ($items as $it) {
                    if (in_array($it->item_status, [PicklistItem::STATUS_SHORT, PicklistItem::STATUS_REJECTED], true)) {
                        DB::table('sales_order_items')
                            ->where('id', $it->order_item_id)
                            ->update([
                                'fulfillment_status' => $it->item_status,
                                'short_qty'          => (int) ($it->failed_qty ?? 0),
                                'updated_at'         => now(),
                            ]);
                    } elseif ((int) $it->qty_picked >= (int) $it->qty_ordered) {
                        DB::table('sales_order_items')
                            ->where('id', $it->order_item_id)
                            ->update([
                                'fulfillment_status' => 'PICKED',
                                'updated_at'         => now(),
                            ]);
                    }
                }

                if ($shortItems->isNotEmpty()) {
                    // Gate order: needs buyer confirmation before shipping.
                    DB::table('sales_orders')
                        ->where('id', $order->id)
                        ->update([
                            'status'                   => 'AWAITING_BUYER_CONFIRMATION',
                            'awaiting_confirmation_at' => now(),
                            'updated_at'               => now(),
                        ]);

                    OrderNeedsBuyerConfirmation::dispatch(
                        (string) $order->id,
                        (string) $picklist->id,
                        $shortItems->map(fn ($it) => [
                            'item_id'     => (string) $it->id,
                            'sku'         => $it->sku,
                            'failed_qty'  => (int) ($it->failed_qty ?? 0),
                            'item_status' => (string) $it->item_status,
                        ])->values()->all(),
                    );
                    continue;
                }

                // No shortages — proceed as before.
                if ($order->status === 'reserved') {
                    $orderService->updateOrder($order, ['status' => 'picked']);
                }
            }
        });
    }
}
