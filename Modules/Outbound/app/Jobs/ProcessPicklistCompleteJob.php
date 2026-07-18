<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Services\OrderReleaseService;

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

    /**
     * Jaring pengaman. Sejak rilis per-pesanan dijalankan inkremental tiap pick
     * (lihat PicklistService::pickItem), umumnya semua pesanan di sini sudah
     * lepas dan OrderReleaseService akan melewatinya. Job tetap dipertahankan
     * untuk menangkap pesanan yang baru tuntas lewat jalur lain, mis. item
     * ditandai SHORT/REJECTED.
     */
    public function handle(OrderReleaseService $orderReleaseService): void
    {
        $picklist = Picklist::with('items.order', 'items.orderItem', 'picker')->find($this->picklistId);

        if (!$picklist || $picklist->status !== Picklist::STATUS_COMPLETED) {
            return;
        }

        $orderIds = $picklist->items->pluck('order_id')->filter()->unique();

        DB::transaction(function () use ($picklist, $orderIds, $orderReleaseService) {
            foreach ($orderIds as $orderId) {
                $orderReleaseService->releaseIfComplete($picklist, (string) $orderId);
            }
        });
    }
}
