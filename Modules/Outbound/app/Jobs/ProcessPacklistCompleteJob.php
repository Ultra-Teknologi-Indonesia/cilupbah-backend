<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Support\InstantOrderClassifier;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
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

        if (InstantOrderClassifier::needsManualDriverDispatch(
            $order->courier_name,
            $order->shipping_provider,
            $order->shipping_type,
        )) {
            Log::info('ProcessPacklistCompleteJob: resi tidak ditarik, kurir dipanggil manual lewat Pengiriman', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'courier_name'  => $order->courier_name,
            ]);

            return;
        }

        if (empty($order->tracking_number)) {
            Log::info('ProcessPacklistCompleteJob: resi tidak ditarik otomatis, menunggu operator di Pengiriman > Siap Kirim', [
                'order_id'      => $order->id,
                'salesorder_no' => $order->salesorder_no,
            ]);

            return;
        }

        try {
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
