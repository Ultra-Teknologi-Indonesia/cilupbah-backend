<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Jobs\ProcessBulkShippingLabelJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Services\BulkShippingLabelService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkShippingLabelController extends Controller
{
    use ApiResponse;

    public function __construct(private BulkShippingLabelService $svc)
    {
    }

    public function store(Request $req): JsonResponse
    {
        $data = $req->validate([
            'order_ids' => 'required|array|min:1',
            'order_ids.*' => 'string|uuid',
            'document_size' => 'nullable|string|in:thermal_100x150,thermal_100x120',
            'per_channel' => 'nullable|array',
        ]);

        $userId = $req->user()->id;
        $key = "bulk-label:{$userId}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Terlalu sering. Coba lagi dalam {$seconds} detik.",
            ], 429);
        }
        RateLimiter::hit($key, 60);

        $perChannelOpts = (array) ($data['per_channel'] ?? []);
        $perChannelOpts['document_size'] = $data['document_size'] ?? BulkShippingLabelService::DEFAULT_SIZE;

        $batch = $this->svc->createBatch(
            $req->user(),
            $data['order_ids'],
            $perChannelOpts,
        );

        ProcessBulkShippingLabelJob::dispatch($batch->id);

        return response()->json(['batch_id' => $batch->id], 202);
    }

    public function show(Request $req, BulkShippingLabelBatch $batch): JsonResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);

        $batch->load(['items' => function ($q) {
            $q->with(['order:id,salesorder_no,channel_order_no,tracking_number,courier_name,transaction_date,ship_by_date,shipping_label_status']);
        }]);

        $waitingShopee = $batch->items
            ->where('status', \Modules\Sales\Models\BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
            ->count();

        $retryable = $batch->items->filter(fn ($i) => $i->isRecoverable())->count();

        return response()->json([
            'id' => $batch->id,
            'status' => $batch->status,
            'total' => $batch->total_count,
            'done' => $batch->done_count,
            'failed' => $batch->failed_count,
            'skipped' => $batch->skipped_count,
            'waiting_shopee' => $waitingShopee,
            'retryable_count' => $retryable,
            'started_at' => $batch->started_at,
            'finished_at' => $batch->finished_at,
            'items' => $batch->items->map(function ($i) {
                $order = $i->order;
                return [
                    'id' => $i->id,
                    'order_id' => $i->order_id,
                    'salesorder_no' => $order?->salesorder_no,
                    'channel' => $i->channel,
                    'channel_order_no' => $order?->channel_order_no,
                    'no_paket' => $order?->channel_order_no,
                    'courier_name' => $order?->courier_name,
                    'tracking_number' => $order?->tracking_number,
                    'tgl_pesanan' => $order?->transaction_date,
                    'tgl_pengiriman' => $order?->ship_by_date,
                    'status' => $i->status,
                    'status_label' => $this->statusLabel($i->status),
                    'status_message' => $this->statusMessage($i),
                    'reason' => $i->reason,
                    'is_instant' => $i->status === \Modules\Sales\Models\BulkShippingLabelItem::STATUS_SKIPPED_INSTANT,
                    'is_retryable' => $i->isRecoverable(),
                    'is_terminal' => $i->isTerminal(),
                ];
            }),
            'pdf_url' => $batch->status === BulkShippingLabelBatch::STATUS_READY && $batch->merged_pdf_path
                ? url("/api/sales/shipping-labels/bulk/{$batch->id}/pdf")
                : null,
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_PENDING => 'Menunggu',
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_DOWNLOADING => 'Mengambil',
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP => 'Menunggu Shopee',
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_DONE => 'Berhasil',
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_SKIPPED_INSTANT => 'Instant courier',
            \Modules\Sales\Models\BulkShippingLabelItem::STATUS_FAILED => 'Gagal',
            default => ucfirst($status),
        };
    }

    private function statusMessage(\Modules\Sales\Models\BulkShippingLabelItem $item): string
    {
        $item_class = \Modules\Sales\Models\BulkShippingLabelItem::class;
        return match (true) {
            $item->status === $item_class::STATUS_PENDING => 'Menunggu antrean',
            $item->status === $item_class::STATUS_DOWNLOADING => 'Sedang mengambil resi...',
            $item->status === $item_class::STATUS_WAITING_SHOPEE_PREP => 'Menunggu Shopee menyiapkan label...',
            $item->status === $item_class::STATUS_DONE => 'Resi berhasil diambil',
            $item->status === $item_class::STATUS_SKIPPED_INSTANT => 'Pesanan dengan instant courier, panggil driver di tab Pengiriman',
            $item->status === $item_class::STATUS_FAILED => match ($item->reason) {
                $item_class::REASON_SHOPEE_PREP_FAILED => 'Shopee gagal menyiapkan label. Coba lagi.',
                $item_class::REASON_SHOPEE_PREP_TIMEOUT => 'Timeout menunggu Shopee. Coba lagi.',
                $item_class::REASON_SHOPEE_DECODE_FAILED => 'File label dari Shopee rusak. Coba lagi.',
                $item_class::REASON_SELF_DESIGN => 'Toko wajib desain label sendiri (self-design).',
                $item_class::REASON_CHANNEL_UNSUPPORTED => 'Channel belum didukung untuk cetak massal.',
                $item_class::REASON_NO_AWB => 'No. Resi belum tersedia dari marketplace.',
                $item_class::REASON_BATCH_CRASHED => 'Proses batch berhenti tak terduga. Coba lagi.',
                $item_class::REASON_STALE_BATCH_REAPED => 'Batch kadaluarsa & dibersihkan. Coba lagi.',
                default => $item->reason ? "Gagal: {$item->reason}" : 'Gagal mengambil resi.',
            },
            default => '',
        };
    }

    public function downloadPdf(Request $req, BulkShippingLabelBatch $batch): StreamedResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);
        abort_unless(
            $batch->status === BulkShippingLabelBatch::STATUS_READY && $batch->merged_pdf_path,
            404,
        );

        $disk = Storage::disk('documents');
        abort_unless($disk->exists($batch->merged_pdf_path), 404);

        return $disk->response(
            $batch->merged_pdf_path,
            "labels-{$batch->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function retryFailed(Request $req, BulkShippingLabelBatch $batch): JsonResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);

        $recoverableOrderIds = $batch->items()
            ->where('status', 'failed')
            ->whereIn('reason', \Modules\Sales\Models\BulkShippingLabelItem::RECOVERABLE_REASONS)
            ->pluck('order_id')
            ->all();

        if (empty($recoverableOrderIds)) {
            return response()->json([
                'message' => 'Tidak ada label gagal yang bisa dicoba ulang.',
            ], 422);
        }

        $newBatch = $this->svc->createBatch(
            $req->user(),
            $recoverableOrderIds,
            $batch->per_channel_opts ?? [],
        );

        ProcessBulkShippingLabelJob::dispatch($newBatch->id);

        return response()->json([
            'batch_id' => $newBatch->id,
            'retried_count' => count($recoverableOrderIds),
        ], 202);
    }
}
