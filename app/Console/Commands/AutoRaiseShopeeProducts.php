<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Jobs\RaiseProductJob;
use Modules\Product\Models\RaiseProduct;
use Modules\Product\Models\RaiseProductDetail;

class AutoRaiseShopeeProducts extends Command
{
    public const MAX_ACTIVE_PER_STORE = 5;

    protected $signature = 'raise-products:auto-raise';

    protected $description = 'Otomatis naikkan (boost) produk Shopee yang sudah lewat window 2 jam';

    public function handle(): int
    {
        $stores = RaiseProduct::query()
            ->where('is_active', true)
            ->whereHas('channelShop.channel', fn ($q) => $q->where('code', 'shopee'))
            ->pluck('id');

        $dispatched = 0;
        $now = now();

        foreach ($stores as $raiseId) {
            $details = RaiseProductDetail::query()
                ->where('raise_product_id', $raiseId)
                ->where('is_active', true)
                ->where('is_repeatable', true)
                ->get();

            if ($details->isEmpty()) {
                continue;
            }

            $active = $details->filter(fn (RaiseProductDetail $d) => $d->end_time && $d->end_time->gt($now));
            $expired = $details->reject(fn (RaiseProductDetail $d) => $d->end_time && $d->end_time->gt($now));

            $slots = self::MAX_ACTIVE_PER_STORE - $active->count();
            if ($slots <= 0 || $expired->isEmpty()) {
                continue;
            }

            $selected = $expired
                ->sortBy(fn (RaiseProductDetail $d) => $d->end_time?->timestamp ?? 0)
                ->take($slots)
                ->pluck('id')
                ->all();

            if (empty($selected)) {
                continue;
            }

            RaiseProductJob::dispatch((string) $raiseId, $selected);
            $dispatched++;
        }

        $this->info("Dispatched auto-raise for {$dispatched} store(s).");

        return self::SUCCESS;
    }
}
