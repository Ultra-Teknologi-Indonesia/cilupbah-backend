<?php

namespace Modules\Sales\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Services\ShippingLabelPrefetchService;

class DispatchDueShippingLabelPrefetch extends Command
{
    protected $signature = 'shipping-labels:dispatch-prefetch {--limit= : Maksimum order yang dijadwalkan per putaran}';

    protected $description = 'Jadwalkan ulang prefetch label yang tertunda secara aman dan terbatas';

    public function handle(ShippingLabelPrefetchService $prefetch): int
    {
        if (! config('shipping-label-prefetch.enabled')) {
            return self::SUCCESS;
        }

        if (! Schema::hasTable('shipping_label_prefetches')) {
            $this->warn('shipping_label_prefetches belum tersedia; prefetch dilewati.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('shipping-label-prefetch.dispatch_batch_size')));
        $count = $prefetch->dispatchDue($limit);
        $this->info("Prefetch label dijadwalkan: {$count} order.");

        return self::SUCCESS;
    }
}
