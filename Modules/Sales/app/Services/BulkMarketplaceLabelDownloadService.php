<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use RuntimeException;
use Throwable;

final class BulkMarketplaceLabelDownloadService
{
    public function __construct(
        private readonly BulkShippingLabelRequestRepository $repository,
        private readonly MarketplaceLabelPdfSplitter $splitter,
        private readonly SalesOrderService $orders,
    ) {}

    public function download(string $batchId, array $ids, string $channel): void
    {
        if (! in_array($channel, ['shopee', 'lazada'], true) || count($ids) > 50) {
            throw new RuntimeException('Kelompok unduhan label tidak valid.');
        }
        $items = $this->repository->downloadItems($batchId, $ids, $channel);
        if (! config('bulk-labels.mass_download_enabled', true)) {
            $this->fallback($batchId, $ids, $channel);

            return;
        }
        $groups = [];
        $locks = [];
        $deadline = microtime(true) + 60;
        try {
            foreach ($items as $item) {
                $order = $item->order;
                if (! $order || strtolower((string) $order->source) !== $channel || empty($order->tracking_number) || empty($order->channel_shop_id)
                    || ($channel === 'shopee' && $order->shipping_label_status !== 'ready')
                    || $order->shipping_label_status === 'self_design_required') {
                    continue;
                }
                $lock = Cache::lock('bulk-label-order:'.$order->id, 180);
                if (! $lock->get()) {
                    continue;
                }
                $locks[] = $lock;
                if (ChannelOrderSideEffectGuard::active((string) $order->id, 'download_bulk_shipping_label') === null
                    || ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'shipping_label', $order->salesorder_no)) {
                    continue;
                }
                if ($this->orders->cachedShippingLabelBytes($order) !== null) {
                    continue;
                }
                $packages = array_values(array_unique(array_filter(array_map('strval', (array) $order->channel_package_ids))));
                // Multi-package orders need per-package evidence, not an assumed page order.
                if (count($packages) > 1 || ($channel === 'lazada' && count($packages) !== 1)) {
                    continue;
                }
                $courier = trim((string) $order->shipping_provider);
                if ($courier === '') {
                    continue;
                }
                $type = $channel === 'shopee' ? ($order->shipping_label_doc_type ?: 'THERMAL_AIR_WAYBILL') : 'PDF';
                $key = json_encode([(string) $order->channel_shop_id, $courier, $type], JSON_THROW_ON_ERROR);
                $groups[$key][(string) $order->id] = ['order' => $order, 'package' => $packages[0] ?? ($order->package_number ?: null)];
            }
            foreach ($groups as $key => $entries) {
                [$shopId, , $type] = json_decode($key, true, flags: JSON_THROW_ON_ERROR);
                foreach (array_chunk($entries, $channel === 'shopee' ? 50 : 20, true) as $chunk) {
                    if (microtime(true) > $deadline) {
                        break 2;
                    }
                    if (count($chunk) < 2) {
                        continue;
                    }
                    try {
                        $bytes = $this->fetch($channel, $shopId, $type, $chunk);
                        $identities = [];
                        foreach ($chunk as $id => $entry) {
                            $identities[$id] = [$entry['order']->channel_order_no, $entry['order']->tracking_number];
                        }
                        $documents = $this->splitter->split($bytes, $identities);
                        unset($bytes);
                        foreach ($chunk as $id => $entry) {
                            $fresh = ChannelOrderSideEffectGuard::active($id, 'cache_bulk_shipping_label');
                            if (! $fresh || $fresh->cancel_requested_at !== null || ! $this->sameShipment($entry['order'], $fresh)) {
                                continue;
                            }
                            $this->orders->cacheShippingLabelBytes($fresh, $documents[$id], $type);
                            $this->repository->markLabelReady($fresh, $type);
                        }
                    } catch (Throwable $exception) {
                        // No unidentified page is attached to an order. Existing individual jobs
                        // retain their own bounded retries and channel error classification.
                        Log::warning('Bulk label download using individual fallback', [
                            'batch_id' => $batchId, 'channel' => $channel,
                            'items' => count($chunk), 'exception' => $exception::class,
                        ]);
                    }
                }
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
        $this->fallback($batchId, $ids, $channel);
    }

    public function fallback(string $batchId, array $ids, string $channel): void
    {
        foreach ($this->repository->downloadItems($batchId, $ids, $channel) as $item) {
            if ($this->repository->releaseForDownload($item)) {
                app(BulkShippingLabelService::class)->dispatchItem($item);
            }
        }
    }

    private function fetch(string $channel, string $shopId, string $type, array $entries): string
    {
        if ($channel === 'lazada') {
            $document = app(LazadaOrderService::class)->getPackageDocument($shopId, array_column($entries, 'package'), 'PDF');

            return app(LazadaShippingDocumentService::class)->download($document, (int) config('bulk-labels.mass_download_max_bytes', 16 * 1024 * 1024));
        }
        $rows = array_map(static fn ($entry) => array_filter([
            'order_sn' => (string) $entry['order']->channel_order_no,
            'package_number' => $entry['package'],
        ], static fn ($value) => $value !== null && $value !== ''), array_values($entries));
        $result = app(ShopeeOrderService::class)->downloadShippingDocumentsMass($shopId, $rows, $type);
        $batch = $result['batches'][0] ?? [];
        if (! empty($result['error']) || count($result['batches'] ?? []) !== 1 || empty($batch['binary'])) {
            throw new RuntimeException('Dokumen Shopee massal belum dapat diunduh.');
        }

        return (string) ($batch['content'] ?? '');
    }

    private function sameShipment(SalesOrder $before, SalesOrder $after): bool
    {
        return $before->tracking_number === $after->tracking_number
            && $before->channel_shop_id === $after->channel_shop_id
            && $before->channel_order_no === $after->channel_order_no
            && $before->channel_package_ids === $after->channel_package_ids;
    }
}
