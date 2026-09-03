<?php

namespace Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Services\BulkShippingLabelService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BulkShippingLabelController extends Controller
{
    use ApiResponse;

    public function __construct(private BulkShippingLabelService $svc) {}

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

            return $this->errorResponse("Terlalu sering. Coba lagi dalam {$seconds} detik.", 429);
        }
        RateLimiter::hit($key, 60);

        $perChannelOpts = (array) ($data['per_channel'] ?? []);
        $perChannelOpts['document_size'] = $data['document_size'] ?? BulkShippingLabelService::DEFAULT_SIZE;

        $batch = $this->svc->createBatch(
            $req->user(),
            $data['order_ids'],
            $perChannelOpts,
        );

        $this->svc->queueBatch($batch);

        return $this->successResponse(['batch_id' => $batch->id], null, 202);
    }

    public function show(Request $req, BulkShippingLabelBatch $batch): JsonResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);

        $batch->load(['items' => function ($q) {
            $q->with(['order:id,salesorder_no,channel_order_no,tracking_number,courier_name,shipping_provider,shipping_type,channel_instant,resolved_shipment_type,transaction_date,ship_by_date,shipping_label_status']);
        }]);

        $waitingShopee = $batch->items
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP)
            ->count();

        $waitingAwb = $batch->items
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
            ->count();

        $retryable = $batch->items->filter(fn ($i) => $i->isRecoverable())->count();

        return $this->successResponse([
            'id' => $batch->id,
            'status' => $batch->status,
            'total' => $batch->total_count,
            'done' => $batch->done_count,
            'failed' => $batch->failed_count,
            'skipped' => $batch->skipped_count,
            'waiting_shopee' => $waitingShopee,
            'waiting_awb' => $waitingAwb,
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
                    'courier_name' => $order?->courier_name ?: $order?->shipping_provider,
                    'tracking_number' => $order?->tracking_number,
                    'tgl_pesanan' => $order?->transaction_date,
                    'tgl_pengiriman' => $order?->ship_by_date,
                    'status' => $i->status,
                    'status_label' => $this->statusLabel($i->status),
                    'status_message' => $this->statusMessage($i),
                    'reason' => $i->reason,
                    'is_instant' => $i->status === BulkShippingLabelItem::STATUS_SKIPPED_INSTANT,
                    'is_retryable' => $i->isRecoverable(),
                    'is_terminal' => $i->isTerminal(),
                ];
            }),
            'pdf_url' => $this->resolvePdfUrl($batch),
        ]);
    }

    private function resolvePdfUrl(BulkShippingLabelBatch $batch): ?string
    {
        if ($batch->status !== BulkShippingLabelBatch::STATUS_READY || empty($batch->merged_pdf_path)) {
            return null;
        }

        return route('api.sales.shipping-labels.bulk.pdf', ['batch' => $batch->id]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            BulkShippingLabelItem::STATUS_PENDING => 'Menunggu',
            BulkShippingLabelItem::STATUS_DOWNLOADING => 'Mengambil',
            BulkShippingLabelItem::STATUS_WAITING_AWB => 'Menarik No. Resi',
            BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP => 'Menunggu Shopee',
            BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP => 'Menunggu Lazada',
            BulkShippingLabelItem::STATUS_DONE => 'Berhasil',
            BulkShippingLabelItem::STATUS_SKIPPED_INSTANT => 'Instant courier',
            BulkShippingLabelItem::STATUS_FAILED => 'Gagal',
            default => ucfirst($status),
        };
    }

    private function statusMessage(BulkShippingLabelItem $item): string
    {
        $item_class = BulkShippingLabelItem::class;

        return match (true) {
            $item->status === $item_class::STATUS_PENDING => 'Menunggu antrean',
            $item->status === $item_class::STATUS_DOWNLOADING => 'Sedang mengambil resi...',
            $item->status === $item_class::STATUS_WAITING_AWB => 'Meminta No. Resi ke marketplace...',
            $item->status === $item_class::STATUS_WAITING_SHOPEE_PREP => 'Menunggu Shopee menyiapkan label...',
            $item->status === $item_class::STATUS_WAITING_LAZADA_PREP => 'Menunggu Lazada menyiapkan label...',
            $item->status === $item_class::STATUS_DONE => 'Resi berhasil diambil',
            $item->status === $item_class::STATUS_SKIPPED_INSTANT => 'Pesanan dengan instant courier, panggil driver di tab Pengiriman',
            $item->status === $item_class::STATUS_FAILED => match (true) {
                $item->reason === $item_class::REASON_PARCEL_ALREADY_SHIPPED
                    || str_contains((string) $item->reason, 'parcel has been shipped')
                    || str_contains((string) $item->reason, 'already shipped')
                    || str_contains((string) $item->reason, 'can not print now') => 'Paket sudah berstatus dikirim (SHIPPED) di marketplace, label resi tidak dapat diunduh lagi.',
                $item->reason === $item_class::REASON_SHOPEE_PREP_FAILED => 'Shopee gagal menyiapkan label. Coba lagi.',
                $item->reason === $item_class::REASON_SHOPEE_PREP_TIMEOUT => 'Timeout menunggu Shopee. Coba lagi.',
                $item->reason === $item_class::REASON_SHOPEE_DECODE_FAILED => 'File label dari Shopee rusak. Coba lagi.',
                $item->reason === $item_class::REASON_SELF_DESIGN => 'Toko wajib desain label sendiri (self-design).',
                $item->reason === $item_class::REASON_CHANNEL_UNSUPPORTED => 'Channel belum didukung untuk cetak massal.',
                $item->reason === $item_class::REASON_NO_AWB => 'No. Resi belum tersedia dari marketplace. Coba lagi.',
                $item->reason === $item_class::REASON_AWB_TIMEOUT => 'Marketplace belum menerbitkan No. Resi setelah beberapa kali dicoba. Coba lagi.',
                $item->reason === $item_class::REASON_CHANNEL_SYNC_PAUSED => 'Sinkronisasi ke marketplace sedang dimatikan, No. Resi tidak bisa ditarik. Hubungi admin.',
                $item->reason === $item_class::REASON_LAZADA_PREP_FAILED => 'Lazada gagal menyiapkan label. Coba lagi.',
                $item->reason === $item_class::REASON_LAZADA_PREP_TIMEOUT => 'Timeout menunggu Lazada. Coba lagi.',
                $item->reason === $item_class::REASON_LAZADA_DECODE_FAILED => 'File label dari Lazada rusak. Coba lagi.',
                $item->reason === $item_class::REASON_BATCH_CRASHED => 'Proses batch berhenti tak terduga. Coba lagi.',
                $item->reason === $item_class::REASON_STALE_BATCH_REAPED => 'Batch kadaluarsa & dibersihkan. Coba lagi.',
                default => $item->reason ? "Gagal: {$item->reason}" : 'Gagal mengambil resi.',
            },
            default => '',
        };
    }

    public function downloadPdf(Request $req, BulkShippingLabelBatch $batch): StreamedResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);
        $path = $this->svc->downloadablePath($batch, $req->user());
        $disk = Storage::disk('documents');

        return $disk->response(
            $path,
            "labels-{$batch->id}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function retryFailed(Request $req, BulkShippingLabelBatch $batch): JsonResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);

        try {
            $newBatch = $this->svc->retryFailed($req->user(), $batch);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse([
            'batch_id' => $newBatch->id,
            'retried_count' => $newBatch->total_count,
        ], null, 202);
    }
}
