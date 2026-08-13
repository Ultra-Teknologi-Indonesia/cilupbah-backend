<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Models\Packlist;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Models\SalesOrder;
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

            $this->requestChannelShipment($order->fresh());
        }
    }

    private function requestChannelShipment(?SalesOrder $order): void
    {
        if (! $order) {
            return;
        }

        $source = strtolower((string) $order->source);

        if (! in_array($source, ['shopee', 'tiktok', 'lazada'], true)) {
            return;
        }

        try {
            if (empty($order->tracking_number)) {
                RequestChannelAwbJob::dispatch($order->id);

                return;
            }

            if (
                $source === 'shopee'
                && ! in_array($order->shipping_label_status, ['ready', 'self_design_required', 'preparing'], true)
            ) {
                PrepareShopeeShippingLabelJob::dispatch($order->id)
                    ->onQueue(config('queue.names.channel_sync'));
            }
        } catch (\Throwable $e) {
            Log::error('ProcessPacklistCompleteJob: gagal dispatch permintaan resi ke channel', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source'        => $source,
                'exception'     => $e->getMessage(),
            ]);
        }
    }
}
