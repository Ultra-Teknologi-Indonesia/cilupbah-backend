<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Outbound\Models\Picklist;
use Modules\Order\Services\OrderService;

class ProcessPicklistCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $picklistId,
    ) {
        $this->onQueue('stock-critical');
    }

    public function handle(OrderService $orderService): void
    {
        $picklist = Picklist::with('items.order')->find($this->picklistId);

        if (!$picklist || $picklist->status !== Picklist::STATUS_COMPLETED) {
            return;
        }

        $orderIds = $picklist->items->pluck('order_id')->unique();

        foreach ($orderIds as $orderId) {
            $order = $picklist->items->firstWhere('order_id', $orderId)->order;

            if ($order && $order->status === 'reserved') {
                $orderService->updateOrder($order, ['status' => 'picked']);
            }
        }
    }
}
