<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Sales\Jobs\CollectShopeeLabelPreparationJob;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\PrepareTikTokShippingLabelJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;

final class ShippingLabelPreparationDispatcher
{
    private const SUPPORTED_CHANNELS = ['shopee', 'tiktok', 'lazada'];

    private const DISPATCH_DEDUPE_SECONDS = 30;

    public function dispatch(SalesOrder $order, bool $prefetch = false): bool
    {
        $channel = strtolower(trim((string) $order->source));

        if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            return false;
        }

        if (empty($order->tracking_number)) {
            return false;
        }

        if (in_array($order->shipping_label_status, ['ready', 'self_design_required'], true)) {
            return false;
        }

        if ($channel === 'shopee' && ! $prefetch) {
            $batchIds = app(BulkShippingLabelRequestRepository::class)->waitingShopeeBatchIds((string) $order->id);
            foreach ($batchIds as $batchId) {
                CollectShopeeLabelPreparationJob::dispatch($batchId)->delay(now()->addSeconds(2));
            }
            if ($batchIds !== []) {
                return true;
            }
        }

        $lock = Cache::lock("shipping-label:dispatch:{$order->id}", 10);
        if (! $lock->get()) {
            return false;
        }

        try {
            $dedupeKey = "shipping-label:dispatch-marker:{$order->id}";
            if (! Cache::add($dedupeKey, true, self::DISPATCH_DEDUPE_SECONDS)) {
                return false;
            }

            $job = match ($channel) {
                'shopee' => new PrepareShopeeShippingLabelJob($order->id, 0, $prefetch),
                'tiktok' => new PrepareTikTokShippingLabelJob($order->id, 0, $prefetch),
                'lazada' => new PrepareLazadaShippingLabelJob($order->id, 0, $prefetch),
            };

            dispatch($job)->afterCommit();

            return true;
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }
}
