<?php

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;

class WarmShippingLabelsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public readonly array $orderIds;

    public function __construct(array $orderIds)
    {
        $this->orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds))));
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.labels.queue', 'labels'));
    }

    public function uniqueId(): string
    {
        $ids = $this->orderIds;
        sort($ids);

        return 'orders:'.hash('sha256', implode(',', $ids));
    }

    public function handle(): void
    {
        if ($this->orderIds === []) {
            return;
        }

        SalesOrder::query()
            ->whereIn('id', $this->orderIds)
            ->whereIn('source', ['shopee', 'tiktok', 'lazada'])
            ->select(['id', 'source', 'tracking_number', 'shipping_label_status'])
            ->cursor()
            ->each(function (SalesOrder $order): void {
                if (in_array($order->shipping_label_status, ['ready', 'self_design_required'], true)) {
                    return;
                }

                if (empty($order->tracking_number)) {
                    RequestChannelAwbJob::dispatch((string) $order->id, 0, false);

                    return;
                }

                app(ShippingLabelPreparationDispatcher::class)->dispatch($order);
            });
    }
}
