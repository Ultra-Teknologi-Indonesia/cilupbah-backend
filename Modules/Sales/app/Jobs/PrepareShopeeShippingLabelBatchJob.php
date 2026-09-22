<?php

declare(strict_types=1);

namespace Modules\Sales\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Channel\Support\ChannelQueue;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\ChannelOperationAttempt;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\BulkShippingLabelService;
use Modules\Sales\Services\ShippingLabelPreparationDispatcher;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use Throwable;

final class PrepareShopeeShippingLabelBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [5, 15, 30];

    public int $uniqueFor = 900;

    private const MAX_ATTEMPTS = 6;

    private const RETRY_DELAYS = [2, 4, 8, 15, 30, 60];

    public function __construct(
        public readonly string $batchId,
        public readonly string $shopId,
        public readonly array $itemIds,
        public readonly string $documentType = 'THERMAL_AIR_WAYBILL',
        public readonly int $attempt = 0,
    ) {
        $this->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $this->onQueue(ChannelQueue::for('shopee', 'label_download'));
    }

    public function uniqueId(): string
    {
        $itemIds = $this->itemIds;
        sort($itemIds);

        return 'batch:'.$this->batchId.':shop:'.$this->shopId.':'.sha1(
            implode('|', $itemIds).'|'.$this->documentType.'|attempt:'.$this->attempt
        );
    }

    public function handle(
        ShopeeOrderService $shopee,
        BulkShippingLabelService $labels,
    ): void {
        $batch = BulkShippingLabelBatch::find($this->batchId);
        if (! $batch || $batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return;
        }

        $items = BulkShippingLabelItem::query()
            ->with('order')
            ->where('batch_id', $this->batchId)
            ->whereIn('id', $this->itemIds)
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_PENDING,
                BulkShippingLabelItem::STATUS_DOWNLOADING,
            ])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $eligible = $this->claimItems($items, $labels);
        if ($eligible->isEmpty()) {
            $labels->tryFinalize($batch);

            return;
        }

        $claims = [];
        $createRows = [];
        $activeRows = [];

        foreach ($eligible as $item) {
            $order = $item->order;
            if (! $order) {
                $labels->failBulkLabelItem($item, BulkShippingLabelItem::REASON_NO_AWB);

                continue;
            }

            $claim = ChannelOperationLedger::claim($order, 'create_shipping_label');
            $claims[(string) $item->id] = $claim['attempt'];

            $row = $this->shippingDocumentRow($order);
            if ($row === null) {
                $this->fallbackToIndividual($item, 'Data paket Shopee tidak lengkap.');

                continue;
            }

            $alreadyPreparing = $order->shipping_label_status === 'preparing';
            if ($alreadyPreparing || ! $claim['should_execute']) {
                $activeRows[] = ['item' => $item, 'order' => $order, 'row' => $row];

                continue;
            }

            $createRows[] = ['item' => $item, 'order' => $order, 'row' => $row];
        }

        if ($createRows !== []) {
            try {
                $created = $shopee->createShippingDocumentsMass(
                    $this->shopId,
                    array_map(static fn (array $entry): array => $entry['row'], $createRows),
                );
            } catch (Throwable $e) {
                foreach ($createRows as $entry) {
                    ChannelOperationLedger::markUncertain($claims[(string) $entry['item']->id], $e);
                    $this->fallbackToIndividual($entry['item'], $e->getMessage());
                }

                Log::warning('PrepareShopeeShippingLabelBatchJob: mass create gagal, fallback individual', [
                    'batch_id' => $this->batchId,
                    'shop_id' => $this->shopId,
                    'items' => count($createRows),
                    'error' => $e->getMessage(),
                ]);
            }

            if (isset($created)) {
                if (! empty($created['error']) && empty($created['results'])) {
                    $error = (string) ($created['error'] ?? 'Shopee mass create gagal.');
                    foreach ($createRows as $entry) {
                        ChannelOperationLedger::markUncertain(
                            $claims[(string) $entry['item']->id],
                            new \RuntimeException($error),
                        );
                        $this->fallbackToIndividual($entry['item'], $error);
                    }
                    $createRows = [];
                }

                foreach ($createRows as $entry) {
                    $key = $this->rowKey($entry['row']);
                    $result = $created['results'][$key] ?? null;
                    if (! is_array($result) || ! ($result['accepted'] ?? false)) {
                        $error = is_array($result) ? (string) ($result['error'] ?? 'Shopee menolak pembuatan label.') : 'Shopee tidak mengembalikan hasil pembuatan label.';
                        if ($this->isRecoverableCreateError($error)) {
                            ChannelOperationLedger::markAccepted($claims[(string) $entry['item']->id]);
                            $activeRows[] = $entry;
                        } else {
                            ChannelOperationLedger::markRejected($claims[(string) $entry['item']->id], $error);
                            $this->markTerminalFailure(
                                $entry,
                                $error,
                                $labels,
                                $claims[(string) $entry['item']->id] ?? null,
                            );
                        }

                        continue;
                    }

                    ChannelOperationLedger::markAccepted($claims[(string) $entry['item']->id]);
                    $activeRows[] = $entry;
                }
            }
        }

        if ($activeRows === []) {
            $labels->tryFinalize($batch);

            return;
        }

        $this->markPreparing($activeRows);

        try {
            $statuses = $shopee->getShippingDocumentResultsMass(
                $this->shopId,
                array_map(static fn (array $entry): array => $entry['row'], $activeRows),
            );
        } catch (Throwable $e) {
            $this->fallbackEntries($activeRows, $e->getMessage());

            return;
        }

        $ready = [];
        $waiting = [];
        foreach ($activeRows as $entry) {
            $status = $statuses['results'][$this->rowKey($entry['row'])] ?? null;
            $statusName = strtoupper((string) ($status['status'] ?? ''));

            if ($statusName === 'READY' || ($status['ready'] ?? false)) {
                $ready[] = $entry;

                continue;
            }

            if ($statusName === 'FAILED') {
                $error = (string) ($status['error'] ?? 'Shopee gagal membuat shipping document.');
                if ($this->isTerminalLabelError($error)) {
                    $this->markTerminalFailure(
                        $entry,
                        $error,
                        $labels,
                        $claims[(string) $entry['item']->id] ?? null,
                    );
                } else {
                    $this->fallbackToIndividual($entry['item'], $error);
                }

                continue;
            }

            $waiting[] = $entry;
        }

        if ($ready !== []) {
            try {
                $downloaded = $shopee->downloadShippingDocumentsMass(
                    $this->shopId,
                    array_map(static fn (array $entry): array => $entry['row'], $ready),
                    $this->documentType,
                );

                $this->stageDownloadedBatches($downloaded, $ready, $labels, $claims);
            } catch (Throwable $e) {
                $this->fallbackEntries($ready, $e->getMessage());
            }
        }

        if ($waiting !== []) {
            if ($this->attempt + 1 >= self::MAX_ATTEMPTS) {
                $this->fallbackEntries($waiting, 'Shipping document Shopee belum READY setelah polling massal.');
            } else {
                self::dispatch(
                    $this->batchId,
                    $this->shopId,
                    collect($waiting)
                        ->map(static fn (array $entry): string => (string) $entry['item']->id)
                        ->values()
                        ->all(),
                    $this->documentType,
                    $this->attempt + 1,
                )->delay(now()->addSeconds(self::RETRY_DELAYS[$this->attempt] ?? 60));
            }
        }

        $labels->tryFinalize($batch->fresh());
    }

    public function failed(Throwable $exception): void
    {
        Log::error('PrepareShopeeShippingLabelBatchJob failed permanently', [
            'batch_id' => $this->batchId,
            'shop_id' => $this->shopId,
            'item_ids' => $this->itemIds,
            'exception' => $exception->getMessage(),
        ]);

        $items = BulkShippingLabelItem::query()
            ->with('order')
            ->where('batch_id', $this->batchId)
            ->whereIn('id', $this->itemIds)
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_PENDING,
                BulkShippingLabelItem::STATUS_DOWNLOADING,
            ])
            ->get();

        foreach ($items as $item) {
            $this->fallbackToIndividual($item, $exception->getMessage());
        }
    }

    private function claimItems(Collection $items, BulkShippingLabelService $labels): Collection
    {
        $eligible = collect();

        foreach ($items as $item) {
            $order = $item->order;
            if (! $order) {
                $labels->failBulkLabelItem($item, BulkShippingLabelItem::REASON_NO_AWB);

                continue;
            }

            if (ChannelOrderSideEffectGuard::active((string) $order->id, 'prepare_shipping_label') === null
                || ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'shipping_label', $order->salesorder_no)) {
                continue;
            }

            if (empty($order->tracking_number)) {
                $labels->failBulkLabelItem($item, BulkShippingLabelItem::REASON_NO_AWB);

                continue;
            }

            BulkShippingLabelItem::query()
                ->whereKey($item->id)
                ->whereIn('status', [
                    BulkShippingLabelItem::STATUS_PENDING,
                    BulkShippingLabelItem::STATUS_DOWNLOADING,
                ])
                ->update([
                    'status' => BulkShippingLabelItem::STATUS_DOWNLOADING,
                    'reason' => null,
                    'updated_at' => now(),
                ]);

            $eligible->push($item->fresh('order'));
        }

        return $eligible;
    }

    private function shippingDocumentRow(SalesOrder $order): ?array
    {
        $orderSn = trim((string) $order->channel_order_no);
        if ($orderSn === '' || trim((string) $order->tracking_number) === '') {
            return null;
        }

        $packageNumber = collect(is_array($order->channel_package_ids)
            ? $order->channel_package_ids
            : [])->map(static fn ($value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '');

        return array_filter([
            'order_sn' => $orderSn,
            'package_number' => $packageNumber,
            'tracking_number' => (string) $order->tracking_number,
            'shipping_document_type' => $this->documentType,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    private function markPreparing(array $entries): void
    {
        foreach ($entries as $entry) {
            $entry['order']->forceFill([
                'shipping_label_status' => 'preparing',
                'shipping_label_doc_type' => $this->documentType,
            ])->saveQuietly();
        }
    }

    private function stageDownloadedBatches(
        array $downloaded,
        array $ready,
        BulkShippingLabelService $labels,
        array $claims,
    ): void {
        $remaining = collect($ready)->keyBy(fn (array $entry): string => $this->rowKey($entry['row']));

        foreach ((array) ($downloaded['batches'] ?? []) as $batch) {
            $content = (string) ($batch['content'] ?? '');
            if ($content === '' || ! ($batch['binary'] ?? false)) {
                continue;
            }

            $entries = collect((array) ($batch['orders'] ?? []))
                ->map(fn (array $row): ?array => $remaining->get($this->rowKey($row)))
                ->filter()
                ->values();
            if ($entries->isEmpty()) {
                continue;
            }

            $updated = $labels->stageShopeeMassDownloadedLabel(
                $this->batchId,
                $entries->map(static fn (array $entry): string => (string) $entry['item']->id)->all(),
                $content,
            );

            if ($updated === 0) {
                continue;
            }

            foreach ($entries as $entry) {
                $order = $entry['order'];
                $order->forceFill([
                    'shipping_label_status' => 'ready',
                    'shipping_label_doc_type' => $this->documentType,
                    'shipping_label_prepared_at' => now(),
                ])->saveQuietly();

                $attempt = $claims[(string) $entry['item']->id] ?? null;
                if ($attempt instanceof ChannelOperationAttempt) {
                    ChannelOperationLedger::markSucceeded($attempt);
                }

                if ($order->driver_call_status === 'pending') {
                    CallShopeeDriverJob::dispatch((string) $order->id)->afterCommit();
                }

                $remaining->forget($this->rowKey($entry['row']));
            }
        }

        foreach ($remaining as $entry) {
            $this->fallbackToIndividual($entry['item'], 'Shopee tidak mengembalikan file PDF massal.');
        }
    }

    private function fallbackEntries(array $entries, string $reason): void
    {
        foreach ($entries as $entry) {
            $this->fallbackToIndividual($entry['item'], $reason);
        }
    }

    private function fallbackToIndividual(BulkShippingLabelItem $item, string $reason): void
    {
        $order = $item->order?->fresh();
        if (! $order) {
            return;
        }

        $item->update([
            'status' => BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
            'reason' => null,
            'updated_at' => now(),
        ]);

        app(ShippingLabelPreparationDispatcher::class)->dispatch($order);

        Log::warning('PrepareShopeeShippingLabelBatchJob: fallback individual', [
            'batch_id' => $this->batchId,
            'item_id' => $item->id,
            'order_sn' => $order->channel_order_no,
            'reason' => Str::limit($reason, 250),
        ]);
    }

    private function markTerminalFailure(
        array $entry,
        string $reason,
        BulkShippingLabelService $labels,
        ?ChannelOperationAttempt $attempt = null,
    ): void {
        $order = $entry['order'];
        $lower = strtolower($reason);
        $isSelfDesign = str_contains($lower, 'self-design') || str_contains($lower, 'self design');
        $status = $isSelfDesign ? 'self_design_required' : 'failed';
        $order->forceFill([
            'shipping_label_status' => $status,
            'shipping_label_doc_type' => null,
            'shipping_label_prepared_at' => now(),
            'shipping_label_raw_data' => array_merge(
                is_array($order->shipping_label_raw_data) ? $order->shipping_label_raw_data : [],
                ['shipping_label_failure' => ['reason' => $reason, 'at' => now()->toISOString()]],
            ),
        ])->saveQuietly();

        if ($attempt !== null) {
            ChannelOperationLedger::markRejected($attempt, $reason);
        }

        $labels->failBulkLabelItem(
            $entry['item'],
            $isSelfDesign ? BulkShippingLabelItem::REASON_SELF_DESIGN : BulkShippingLabelItem::REASON_SHOPEE_PREP_FAILED,
        );
    }

    private function isRecoverableCreateError(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'duplicate')
            || str_contains($error, 'already')
            || str_contains($error, 'exist')
            || str_contains($error, 'not ready')
            || str_contains($error, 'processing')
            || str_contains($error, 'try again')
            || str_contains($error, 'temporar');
    }

    private function isTerminalLabelError(string $error): bool
    {
        $error = strtolower($error);

        return str_contains($error, 'self-design')
            || str_contains($error, 'self design')
            || str_contains($error, 'already shipped')
            || str_contains($error, 'parcel has been shipped');
    }

    private function rowKey(array $row): string
    {
        return (string) ($row['order_sn'] ?? '').'|'.(string) ($row['package_number'] ?? '');
    }
}
