<?php

namespace Modules\Sales\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

class BulkShippingLabelService
{
    public const CHANNEL_SHOPEE = 'shopee';
    public const CHANNEL_TIKTOK = 'tiktok';
    public const CHANNEL_LAZADA = 'lazada';
    public const CHANNEL_WC = 'woocommerce';
    public const CHANNEL_MANUAL = 'manual';

    public const SUPPORTED_CHANNELS = [
        self::CHANNEL_SHOPEE,
        self::CHANNEL_TIKTOK,
    ];

    public const SHOPEE_PREP_DEADLINE_SECONDS = 90;
    public const SHOPEE_PREP_POLL_SECONDS = 5;
    public const TIKTOK_DOWNLOAD_TIMEOUT = 20;
    public const TIKTOK_DOWNLOAD_RETRIES = 2;
    public const TIKTOK_PARALLEL_LANES = 8;
    public const SPLIT_SUB_BATCH_THRESHOLD = 500;
    public const SUB_BATCH_SIZE = 100;

    public function __construct(private SalesOrderService $salesOrderService)
    {
    }

    public function createBatch(User $user, array $orderIds, array $perChannelOpts): BulkShippingLabelBatch
    {
        return DB::transaction(function () use ($user, $orderIds, $perChannelOpts) {
            $batch = BulkShippingLabelBatch::create([
                'user_id' => $user->id,
                'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
                'per_channel_opts' => $perChannelOpts,
                'total_count' => count($orderIds),
                'done_count' => 0,
                'failed_count' => 0,
            ]);

            $orders = SalesOrder::whereIn('id', $orderIds)
                ->get()
                ->keyBy('id');

            foreach ($orderIds as $orderId) {
                $order = $orders->get($orderId);
                $channel = $order?->source ?? self::CHANNEL_MANUAL;

                [$status, $reason] = $this->initialItemStatus($order, $channel);

                BulkShippingLabelItem::create([
                    'batch_id' => $batch->id,
                    'order_id' => $orderId,
                    'channel' => $channel,
                    'status' => $status,
                    'reason' => $reason,
                ]);
            }

            $batch->recomputeCounts();

            return $batch->fresh();
        });
    }

    private function initialItemStatus(?SalesOrder $order, string $channel): array
    {
        if (! $order) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_NO_AWB];
        }

        if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED];
        }

        $hasAwb = ! empty($order->tracking_number)
            || ! empty($order->awb_no)
            || ! empty($order->channel_order_no);

        if (! $hasAwb) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_NO_AWB];
        }

        return [BulkShippingLabelItem::STATUS_PENDING, null];
    }

    public function processPendingItems(BulkShippingLabelBatch $batch, ?array $perChannelOpts): void
    {
        $pending = $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->get();

        $shopeeItems = $pending->where('channel', self::CHANNEL_SHOPEE);
        $tikTokItems = $pending->where('channel', self::CHANNEL_TIKTOK);
        $otherItems = $pending->whereNotIn('channel', self::SUPPORTED_CHANNELS);

        foreach ($shopeeItems as $item) {
            $this->processItem($item, $perChannelOpts);
        }
        foreach ($otherItems as $item) {
            $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED);
        }
        if ($tikTokItems->isNotEmpty()) {
            $this->processTikTokBatch($tikTokItems->values(), $perChannelOpts);
        }

        $batch->recomputeCounts();
    }

    private function processTikTokBatch($items, ?array $perChannelOpts): void
    {
        $options = ($perChannelOpts[self::CHANNEL_TIKTOK] ?? [
            'document_type' => 'SHIPPING_LABEL',
            'document_size' => 'A6',
        ]);

        $urlMap = [];
        foreach ($items as $item) {
            try {
                $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
                $order = SalesOrder::find($item->order_id);
                if (! $order) {
                    $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
                    continue;
                }
                $result = $this->salesOrderService->getShippingLabel($order, $options);
                $type = $result['type'] ?? null;

                if ($type === 'url' && ! empty($result['doc_url'])) {
                    $urlMap[$item->id] = $result['doc_url'];
                    continue;
                }
                if ($type === 'blob' || ! empty($result['data'])) {
                    $bytes = $this->decodeLabelPayload($result);
                    if ($bytes === null) {
                        $this->fail($item, 'tiktok_decode_failed');
                        continue;
                    }
                    $this->succeed($item, $bytes);
                    continue;
                }
                $this->fail($item, 'tiktok_no_label');
            } catch (Throwable $e) {
                Log::warning('TikTok batch prep failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($item, substr($e->getMessage(), 0, 250));
            }
        }

        if (empty($urlMap)) {
            return;
        }

        foreach (array_chunk($urlMap, self::TIKTOK_PARALLEL_LANES, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $reqs = [];
                foreach ($chunk as $itemId => $url) {
                    $reqs[$itemId] = $pool
                        ->as((string) $itemId)
                        ->timeout(self::TIKTOK_DOWNLOAD_TIMEOUT)
                        ->retry(self::TIKTOK_DOWNLOAD_RETRIES, 500)
                        ->get($url);
                }
                return $reqs;
            });

            foreach ($chunk as $itemId => $_url) {
                $item = $items->firstWhere('id', $itemId);
                if (! $item) continue;
                $response = $responses[$itemId] ?? null;
                if ($response instanceof \Throwable || $response === null || ! $response->successful()) {
                    $this->fail($item, 'tiktok_download_failed');
                    continue;
                }
                $this->succeed($item, $response->body());
            }
        }
    }

    public function processItem(BulkShippingLabelItem $item, ?array $perChannelOpts): void
    {
        try {
            $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
            $order = SalesOrder::find($item->order_id);
            if (! $order) {
                $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
                return;
            }

            $options = $perChannelOpts[$item->channel] ?? [
                'document_type' => $item->channel === self::CHANNEL_SHOPEE ? 'AWB' : 'SHIPPING_LABEL',
                'document_size' => 'A6',
            ];

            match ($item->channel) {
                self::CHANNEL_SHOPEE => $this->processShopee($item, $order, $options),
                self::CHANNEL_TIKTOK => $this->processTikTok($item, $order, $options),
                default => $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED),
            };
        } catch (Throwable $e) {
            Log::error('BulkShippingLabelService.processItem failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($item, substr($e->getMessage(), 0, 250));
        }
    }

    private function processShopee(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        $result = $this->salesOrderService->getShippingLabel($order, $options);
        $status = $result['status'] ?? null;

        if ($status === 'ready' && ! empty($result['data'])) {
            $bytes = $this->decodeLabelPayload($result);
            if ($bytes === null) {
                $this->fail($item, 'shopee_decode_failed');
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        if (in_array($status, ['preparing', null], true)) {
            PrepareShopeeShippingLabelJob::dispatch($order->id, $options);
            $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP]);
            return;
        }

        if ($status === 'failed') {
            $this->fail($item, 'shopee_prep_failed');
            return;
        }

        if ($status === 'self_design_required') {
            $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);
            return;
        }

        $this->fail($item, "shopee_unknown:{$status}");
    }

    private function processTikTok(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        $result = $this->salesOrderService->getShippingLabel($order, $options);
        $type = $result['type'] ?? null;

        if ($type === 'url' && ! empty($result['doc_url'])) {
            $bytes = $this->downloadUrl($result['doc_url']);
            if ($bytes === null) {
                $this->fail($item, 'tiktok_download_failed');
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        if ($type === 'blob' || ! empty($result['data'])) {
            $bytes = $this->decodeLabelPayload($result);
            if ($bytes === null) {
                $this->fail($item, 'tiktok_decode_failed');
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        $this->fail($item, 'tiktok_no_label');
    }

    private function decodeLabelPayload(array $result): ?string
    {
        if (isset($result['bytes'])) {
            return $result['bytes'];
        }
        if (isset($result['data'])) {
            $decoded = base64_decode($result['data'], true);
            return $decoded === false ? null : $decoded;
        }
        return null;
    }

    private function downloadUrl(string $url): ?string
    {
        $response = Http::timeout(self::TIKTOK_DOWNLOAD_TIMEOUT)
            ->retry(self::TIKTOK_DOWNLOAD_RETRIES, 500)
            ->get($url);
        if (! $response->successful()) {
            return null;
        }
        return $response->body();
    }

    private function succeed(BulkShippingLabelItem $item, string $bytes): void
    {
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_DONE,
            'pdf_bytes' => $bytes,
            'downloaded_at' => now(),
            'reason' => null,
        ]);
    }

    private function fail(BulkShippingLabelItem $item, string $reason): void
    {
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_FAILED,
            'reason' => $reason,
        ]);
    }

    public function awaitShopeePreparations(BulkShippingLabelBatch $batch, Carbon $deadline): void
    {
        while (now()->lt($deadline)) {
            $waiting = $batch->items()
                ->where('status', BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
                ->get();

            if ($waiting->isEmpty()) {
                return;
            }

            foreach ($waiting as $item) {
                $order = SalesOrder::find($item->order_id);
                if (! $order) {
                    $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
                    continue;
                }

                $status = $order->shipping_label_status ?? null;
                if ($status === 'ready') {
                    $options = ($batch->per_channel_opts[self::CHANNEL_SHOPEE] ?? [
                        'document_type' => 'AWB',
                        'document_size' => 'A6',
                    ]);
                    $this->processShopee($item, $order, $options);
                } elseif ($status === 'failed') {
                    $this->fail($item, 'shopee_prep_failed');
                } elseif ($status === 'self_design_required') {
                    $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);
                }
            }

            $batch->recomputeCounts();

            if ($batch->fresh()->items()
                ->where('status', BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
                ->exists()) {
                sleep(self::SHOPEE_PREP_POLL_SECONDS);
            } else {
                return;
            }
        }

        // Deadline hit — flag any still-waiting items as timeout
        $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
            ->get()
            ->each(fn ($i) => $this->fail($i, BulkShippingLabelItem::REASON_SHOPEE_PREP_TIMEOUT));

        $batch->recomputeCounts();
    }

    public function mergeAndPersist(BulkShippingLabelBatch $batch): void
    {
        $items = $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_DONE)
            ->orderBy('created_at')
            ->get();

        if ($items->isEmpty()) {
            $batch->update([
                'status' => BulkShippingLabelBatch::STATUS_FAILED,
                'finished_at' => now(),
            ]);
            return;
        }

        $pdf = new Fpdi();
        foreach ($items as $item) {
            try {
                $pageCount = $pdf->setSourceFile(StreamReader::createByString($item->pdf_bytes));
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl = $pdf->importPage($p);
                    $size = $pdf->getTemplateSize($tpl);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tpl);
                }
            } catch (Throwable $e) {
                Log::warning('FPDI merge failed for item', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($item, 'merge_failed:'.substr($e->getMessage(), 0, 200));
            }
        }

        $blob = $pdf->Output('S');
        $path = "bulk-labels/{$batch->id}.pdf";
        Storage::disk('documents')->put($path, $blob);

        // Bersihkan pdf_bytes untuk hemat storage row
        $batch->items()->update(['pdf_bytes' => null]);

        $batch->update([
            'merged_pdf_path' => $path,
            'merged_pdf_bytes' => strlen($blob),
        ]);

        $batch->recomputeCounts();
    }

    public function markCrashed(BulkShippingLabelBatch $batch): void
    {
        $batch->items()
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_PENDING,
                BulkShippingLabelItem::STATUS_DOWNLOADING,
                BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
            ])
            ->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => BulkShippingLabelItem::REASON_BATCH_CRASHED,
            ]);
        $batch->update([
            'status' => BulkShippingLabelBatch::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        $batch->recomputeCounts();
    }
}
