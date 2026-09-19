<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs\Concerns;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\SalesOrder;

trait UsesShippingLabelPreparationLock
{
    private function withShippingLabelPreparationLock(
        SalesOrder $order,
        callable $callback,
    ): void {
        $lock = Cache::lock("shipping-label:prepare:{$order->id}", 240);

        if (! $lock->get()) {
            Log::info('Shipping label preparation already running', [
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'job' => static::class,
            ]);

            return;
        }

        try {
            $callback();
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }
}
