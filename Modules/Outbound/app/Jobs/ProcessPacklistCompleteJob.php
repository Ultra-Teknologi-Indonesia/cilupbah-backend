<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Outbound\Models\Packlist;
use Modules\Sales\Services\SalesOrderService as OrderService;

class ProcessPacklistCompleteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [3, 10, 30];

    public function __construct(
        protected string $packlistId,
    ) {
        $this->onQueue(config('queue.names.stock_critical'));
    }

    public function handle(OrderService $orderService): void
    {
        $packlist = Packlist::with('order', 'packer')->find($this->packlistId);

        if (!$packlist || $packlist->status !== Packlist::STATUS_COMPLETED) {
            return;
        }

        $order = $packlist->order;

        if ($order && $order->status === 'picked') {
            $orderService->updateOrder($order, ['status' => 'packed'], $packlist->packer);
        }
    }
}
