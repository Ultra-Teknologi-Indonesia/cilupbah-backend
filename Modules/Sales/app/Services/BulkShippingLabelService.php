<?php

namespace Modules\Sales\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
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
        self::CHANNEL_LAZADA,
    ];

    public const SIZE_100X150 = 'thermal_100x150';
    public const SIZE_100X120 = 'thermal_100x120';
    public const DEFAULT_SIZE = self::SIZE_100X150;

    private const SIZE_DIMENSIONS_MM = [
        self::SIZE_100X150 => [100.0, 150.0],
        self::SIZE_100X120 => [100.0, 120.0],
    ];

    private const BBOX_MIN_SIDE_MM = 20.0;

    private const BBOX_FULL_SHEET_RATIO = 0.95;

    private const BBOX_SAFE_MARGIN_MM = 2.0;

    public const TIKTOK_DOWNLOAD_TIMEOUT = 20;
    public const TIKTOK_DOWNLOAD_RETRIES = 2;
    public const TIKTOK_PARALLEL_LANES = 8;
    public const SPLIT_SUB_BATCH_THRESHOLD = 500;
    public const SUB_BATCH_SIZE = 100;

    private const INSTANT_COURIER_KEYWORDS = [
        'INSTANT',
        'SAMEDAY_INSTANT',
        'SAME DAY INSTANT',
        'GOJEK',
        'GRAB',
        'LALAMOVE',
        'BORZO',
        'DEALIVER',
        'LEX ID',
        'PICKUP',
    ];

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
                'skipped_count' => 0,
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

        if ($this->isInstantCourier($order)) {
            return [BulkShippingLabelItem::STATUS_SKIPPED_INSTANT, BulkShippingLabelItem::REASON_INSTANT_COURIER];
        }

        $hasAwb = ! empty($order->tracking_number)
            || ! empty($order->awb_no)
            || ! empty($order->channel_order_no);

        if (! $hasAwb) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_NO_AWB];
        }

        return [BulkShippingLabelItem::STATUS_PENDING, null];
    }

    public function isInstantCourier(?SalesOrder $order): bool
    {
        if (! $order) {
            return false;
        }
        $haystack = strtoupper(trim((string) ($order->courier_name ?? '').' '.($order->courier_type_code ?? '')));
        if ($haystack === '') {
            return false;
        }
        foreach (self::INSTANT_COURIER_KEYWORDS as $needle) {
            if (Str::contains($haystack, $needle)) {

                if ($needle === 'INSTANT' && strtolower((string) $order->source) === self::CHANNEL_SHOPEE
                    && Str::startsWith($haystack, 'SPX')) {
                    continue;
                }
                return true;
            }
        }
        return false;
    }

    public function processPendingItems(BulkShippingLabelBatch $batch, ?array $perChannelOpts): void
    {
        $pending = $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->get();

        $shopeeItems = $pending->where('channel', self::CHANNEL_SHOPEE);
        $tikTokItems = $pending->where('channel', self::CHANNEL_TIKTOK);
        $lazadaItems = $pending->where('channel', self::CHANNEL_LAZADA);
        $otherItems = $pending->whereNotIn('channel', self::SUPPORTED_CHANNELS);

        foreach ($shopeeItems as $item) {
            $this->processItem($item, $perChannelOpts);
        }
        foreach ($lazadaItems as $item) {
            $this->processItem($item, $perChannelOpts);
        }
        foreach ($otherItems as $item) {
            $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED);
        }
        if ($tikTokItems->isNotEmpty()) {
            $this->processTikTokBatch($tikTokItems->values(), $perChannelOpts);
        }

        $batch->recomputeCounts();

        $this->tryFinalize($batch);
    }

    public function resolveChannelOptions(string $channel): array
    {
        return match ($channel) {
            self::CHANNEL_SHOPEE => [
                'document_type' => 'THERMAL_AIR_WAYBILL',
                'document_size' => null,
            ],
            self::CHANNEL_TIKTOK => [
                'document_type' => 'SHIPPING_LABEL',
                'document_size' => 'A6',
            ],
            self::CHANNEL_LAZADA => [
                'document_type' => 'PDF',
                'document_size' => null,
            ],
            default => [],
        };
    }

    private function resolveTargetSize(BulkShippingLabelBatch $batch): array
    {
        $opts = $batch->per_channel_opts ?? [];
        $sizeKey = $opts['document_size'] ?? self::DEFAULT_SIZE;

        return self::SIZE_DIMENSIONS_MM[$sizeKey] ?? self::SIZE_DIMENSIONS_MM[self::DEFAULT_SIZE];
    }

    public function preprocessPdfForFpdi(string $srcPdfBytes): string
    {
        $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            return $srcPdfBytes;
        }

        $inTmp = tempnam(sys_get_temp_dir(), 'lbl_in_');
        $outTmp = tempnam(sys_get_temp_dir(), 'lbl_out_');
        try {
            file_put_contents($inTmp, $srcPdfBytes);
            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile=%s %s 2>&1',
                escapeshellcmd($gs),
                escapeshellarg($outTmp),
                escapeshellarg($inTmp),
            );
            @shell_exec($cmd);
            if (is_file($outTmp) && filesize($outTmp) > 0) {
                $result = file_get_contents($outTmp);
                if ($result !== false && $result !== '') {
                    return $result;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('preprocessPdfForFpdi: gs gagal, pakai bytes asli', ['error' => $e->getMessage()]);
        } finally {
            @unlink($inTmp);
            @unlink($outTmp);
        }
        return $srcPdfBytes;
    }

    private function isShopeeA4Sheet(?string $channel, float $srcW, float $srcH): bool
    {
        if ($channel !== self::CHANNEL_SHOPEE) {
            return false;
        }

        $portrait = $srcW >= 200 && $srcW <= 220 && $srcH >= 285 && $srcH <= 302;
        $landscape = $srcW >= 285 && $srcW <= 302 && $srcH >= 200 && $srcH <= 220;

        return $portrait || $landscape;
    }

    private function detectInkBBoxMm(string $pdfBytes, int $page): ?array
    {
        $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lbl_bbox_');
        try {
            file_put_contents($tmp, $pdfBytes);
            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -dFirstPage=%d -dLastPage=%d -sDEVICE=bbox %s 2>&1',
                escapeshellcmd($gs),
                $page,
                $page,
                escapeshellarg($tmp),
            );
            $out = (string) @shell_exec($cmd);

            if (! preg_match('/%%HiResBoundingBox:\s*([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)/', $out, $m)) {
                return null;
            }

            $ptToMm = 25.4 / 72.0;
            $box = [
                (float) $m[1] * $ptToMm,
                (float) $m[2] * $ptToMm,
                (float) $m[3] * $ptToMm,
                (float) $m[4] * $ptToMm,
            ];

            return ($box[2] > $box[0] && $box[3] > $box[1]) ? $box : null;
        } catch (\Throwable $e) {
            Log::warning('detectInkBBoxMm gagal', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private function placementFromBBox(float $srcW, float $srcH, array $bbox, float $targetW, float $targetH): ?array
    {
        $x0 = max(0.0, min($bbox[0], $srcW));
        $y0 = max(0.0, min($bbox[1], $srcH));
        $x1 = max(0.0, min($bbox[2], $srcW));
        $y1 = max(0.0, min($bbox[3], $srcH));

        $boxW = $x1 - $x0;
        $boxH = $y1 - $y0;

        if ($boxW < self::BBOX_MIN_SIDE_MM || $boxH < self::BBOX_MIN_SIDE_MM) {
            return null;
        }

        if (($boxW * $boxH) >= ($srcW * $srcH * self::BBOX_FULL_SHEET_RATIO)) {
            return null;
        }

        $usableW = max(1.0, $targetW - 2 * self::BBOX_SAFE_MARGIN_MM);
        $usableH = max(1.0, $targetH - 2 * self::BBOX_SAFE_MARGIN_MM);
        $scale = min($usableW / $boxW, $usableH / $boxH);

        $topOffset = $srcH - $y1;

        return [
            -$x0 * $scale + ($targetW - $boxW * $scale) / 2,
            -$topOffset * $scale + self::BBOX_SAFE_MARGIN_MM,
            $srcW * $scale,
            $srcH * $scale,
        ];
    }

    private function placementOnTarget(float $srcW, float $srcH, float $targetW, float $targetH, ?string $channel = null): array
    {
        $isShopeeA4Portrait = $channel === self::CHANNEL_SHOPEE
            && $srcW >= 200 && $srcW <= 220
            && $srcH >= 285 && $srcH <= 302;

        $isShopeeA4Landscape = $channel === self::CHANNEL_SHOPEE
            && $srcW >= 285 && $srcW <= 302
            && $srcH >= 200 && $srcH <= 220;

        if ($isShopeeA4Landscape) {
            $effW = $srcW / 2;
            $effH = $srcH;
        } elseif ($isShopeeA4Portrait) {
            $effW = $srcW / 2;
            $effH = $srcH / 2;
        } else {
            $effW = $srcW;
            $effH = $srcH;
        }

        $aspect = $effH / $effW;
        $scale = $aspect > 1.6
            ? $targetW / $effW
            : min($targetW / $effW, $targetH / $effH);

        $renderW = $effW * $scale;
        $renderH = $effH * $scale;

        $x = ($targetW - $renderW) / 2;
        $y = 0.0;

        return [$x, $y, $srcW * $scale, $srcH * $scale];
    }

    public function normalizeToTarget(string $srcPdfBytes, string $sizeKey = self::DEFAULT_SIZE, ?string $channel = null): string
    {
        [$targetW, $targetH] = self::SIZE_DIMENSIONS_MM[$sizeKey] ?? self::SIZE_DIMENSIONS_MM[self::DEFAULT_SIZE];

        try {
            $prepared = $this->preprocessPdfForFpdi($srcPdfBytes);
            $out = new Fpdi('P', 'mm', [$targetW, $targetH]);
            $pageCount = $out->setSourceFile(StreamReader::createByString($prepared));
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $out->importPage($p);
                $src = $out->getTemplateSize($tpl);
                $srcW = (float) $src['width'];
                $srcH = (float) $src['height'];

                $placement = null;
                if (! $this->isShopeeA4Sheet($channel, $srcW, $srcH)) {
                    $bbox = $this->detectInkBBoxMm($prepared, $p);
                    if ($bbox !== null) {
                        $placement = $this->placementFromBBox($srcW, $srcH, $bbox, $targetW, $targetH);
                    }
                }

                $placement ??= $this->placementOnTarget($srcW, $srcH, $targetW, $targetH, $channel);

                [$x, $y, $drawW, $drawH] = $placement;

                $out->AddPage('P', [$targetW, $targetH]);
                $out->useTemplate($tpl, $x, $y, $drawW, $drawH, false);
            }

            return $out->Output('S');
        } catch (\Throwable $e) {
            Log::warning('normalizeToTarget: FPDI gagal, return PDF asli', [
                'error' => $e->getMessage(),
                'channel' => $channel,
                'size_key' => $sizeKey,
            ]);

            return $srcPdfBytes;
        }
    }

    private function processTikTokBatch($items, ?array $perChannelOpts): void
    {
        $options = $this->resolveChannelOptions(self::CHANNEL_TIKTOK);

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

            if ($this->isInstantCourier($order)) {
                $this->skipInstant($item);
                return;
            }

            $options = $this->resolveChannelOptions($item->channel);

            match ($item->channel) {
                self::CHANNEL_SHOPEE => $this->processShopee($item, $order, $options),
                self::CHANNEL_TIKTOK => $this->processTikTok($item, $order, $options),
                self::CHANNEL_LAZADA => $this->processLazada($item, $order, $options),
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
                $this->fail($item, BulkShippingLabelItem::REASON_SHOPEE_DECODE_FAILED);
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        if (in_array($status, ['preparing', null], true)) {
            PrepareShopeeShippingLabelJob::dispatch($order->id);
            $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP]);
            return;
        }

        if ($status === 'failed') {
            $this->fail($item, BulkShippingLabelItem::REASON_SHOPEE_PREP_FAILED);
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

    private function processLazada(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        try {
            $result = $this->salesOrderService->getShippingLabel($order, $options);
        } catch (ShippingLabelPreparingException $e) {
            // Label belum siap → siapkan async & parkir item (mirror Shopee), jangan langsung gagal.
            PrepareLazadaShippingLabelJob::dispatch($order->id)
                ->onQueue(config('queue.names.channel_sync'));
            $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP]);
            return;
        } catch (\RuntimeException $e) {
            // getShippingLabel melempar RuntimeException untuk order SOF/DBS (self-design).
            $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);
            return;
        }

        $type = $result['type'] ?? null;

        if ($type === 'url' && ! empty($result['url'])) {
            $bytes = $this->downloadUrl($result['url']);
            if ($bytes === null) {
                $this->fail($item, BulkShippingLabelItem::REASON_LAZADA_DECODE_FAILED);
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        if ($type === 'base64' && ! empty($result['document_base64'])) {
            $bytes = base64_decode($result['document_base64'], true);
            if ($bytes === false) {
                $this->fail($item, BulkShippingLabelItem::REASON_LAZADA_DECODE_FAILED);
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        if (! empty($result['data'])) {
            $bytes = $this->decodeLabelPayload($result);
            if ($bytes === null) {
                $this->fail($item, BulkShippingLabelItem::REASON_LAZADA_DECODE_FAILED);
                return;
            }
            $this->succeed($item, $bytes);
            return;
        }

        // Belum siap tanpa exception → parkir dan tunggu prep job.
        PrepareLazadaShippingLabelJob::dispatch($order->id)
            ->onQueue(config('queue.names.channel_sync'));
        $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP]);
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

    private function skipInstant(BulkShippingLabelItem $item): void
    {
        $item->update([
            'status' => BulkShippingLabelItem::STATUS_SKIPPED_INSTANT,
            'reason' => BulkShippingLabelItem::REASON_INSTANT_COURIER,
        ]);
    }

    public function onOrderLabelReady(string $orderId): void
    {
        $items = BulkShippingLabelItem::where('order_id', $orderId)
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
            ])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $order = SalesOrder::find($orderId);
        if (! $order) {
            foreach ($items as $item) {
                $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
            }
            $this->finalizeAffectedBatches($items);
            return;
        }

        $labelStatus = $order->shipping_label_status ?? null;

        foreach ($items as $item) {
            try {
                $isLazada = $item->channel === self::CHANNEL_LAZADA;

                if ($labelStatus === 'ready') {
                    $options = $this->resolveChannelOptions($item->channel);
                    $isLazada
                        ? $this->processLazada($item, $order, $options)
                        : $this->processShopee($item, $order, $options);
                } elseif ($labelStatus === 'self_design_required') {
                    $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);
                } elseif ($labelStatus === 'failed') {
                    $this->fail($item, $isLazada
                        ? BulkShippingLabelItem::REASON_LAZADA_PREP_FAILED
                        : BulkShippingLabelItem::REASON_SHOPEE_PREP_FAILED);
                }

            } catch (Throwable $e) {
                Log::warning('onOrderLabelReady: transition failed', [
                    'item_id' => $item->id,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($item, substr($e->getMessage(), 0, 250));
            }
        }

        $this->finalizeAffectedBatches($items);
    }

    private function finalizeAffectedBatches($items): void
    {
        $batchIds = $items->pluck('batch_id')->unique()->values();
        foreach ($batchIds as $batchId) {
            $batch = BulkShippingLabelBatch::find($batchId);
            if ($batch) {
                $this->tryFinalize($batch);
            }
        }
    }

    public function tryFinalize(BulkShippingLabelBatch $batch): void
    {
        Cache::lock("bulk-label-finalize:{$batch->id}", 15)->block(5, function () use ($batch) {
            $fresh = BulkShippingLabelBatch::find($batch->id);
            if (! $fresh) {
                return;
            }
            if ($fresh->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
                return;
            }

            $hasTransient = $fresh->items()
                ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
                ->exists();

            if ($hasTransient) {
                return;
            }

            $this->mergeAndPersist($fresh);
        });
    }

    public function forceFinalize(BulkShippingLabelBatch $batch, string $reason): void
    {
        Cache::lock("bulk-label-finalize:{$batch->id}", 15)->block(5, function () use ($batch, $reason) {
            $fresh = BulkShippingLabelBatch::find($batch->id);
            if (! $fresh) {
                return;
            }
            if ($fresh->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
                return;
            }

            $fresh->items()
                ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
                ->update([
                    'status' => BulkShippingLabelItem::STATUS_FAILED,
                    'reason' => $reason,
                ]);

            $this->mergeAndPersist($fresh);
        });
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
            $batch->recomputeCounts();
            return;
        }

        [$targetW, $targetH] = $this->resolveTargetSize($batch);

        $pdf = new Fpdi('P', 'mm', [$targetW, $targetH]);
        foreach ($items as $item) {
            try {
                $preparedBytes = $this->preprocessPdfForFpdi($item->pdf_bytes);
                $pageCount = $pdf->setSourceFile(StreamReader::createByString($preparedBytes));
                for ($p = 1; $p <= $pageCount; $p++) {
                    $tpl = $pdf->importPage($p);
                    $src = $pdf->getTemplateSize($tpl);
                    [$x, $y, $drawW, $drawH] = $this->placementOnTarget(
                        (float) $src['width'],
                        (float) $src['height'],
                        $targetW,
                        $targetH,
                        $item->channel,
                    );

                    $pdf->AddPage('P', [$targetW, $targetH]);
                    $pdf->useTemplate($tpl, $x, $y, $drawW, $drawH, false);
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

        $batch->items()->update(['pdf_bytes' => null]);

        $batch->update([
            'merged_pdf_path' => $path,
            'merged_pdf_bytes' => strlen($blob),
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'finished_at' => now(),
        ]);

        $batch->recomputeCounts();
    }

    public function markCrashed(BulkShippingLabelBatch $batch): void
    {
        $batch->items()
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
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
