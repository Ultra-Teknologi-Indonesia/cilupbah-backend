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

        $batch = $this->svc->createBatch(
            $req->user(),
            $data['order_ids'],
            $data['per_channel'] ?? [],
        );

        ProcessBulkShippingLabelJob::dispatch($batch->id);

        return response()->json(['batch_id' => $batch->id], 202);
    }

    public function show(Request $req, BulkShippingLabelBatch $batch): JsonResponse
    {
        abort_unless($batch->user_id === $req->user()->id, 403);

        $batch->load('items');

        $waitingShopee = $batch->items
            ->where('status', 'waiting_shopee_prep')
            ->count();

        return response()->json([
            'id' => $batch->id,
            'status' => $batch->status,
            'total' => $batch->total_count,
            'done' => $batch->done_count,
            'failed' => $batch->failed_count,
            'waiting_shopee' => $waitingShopee,
            'started_at' => $batch->started_at,
            'finished_at' => $batch->finished_at,
            'items' => $batch->items->map(fn ($i) => [
                'order_id' => $i->order_id,
                'channel' => $i->channel,
                'status' => $i->status,
                'reason' => $i->reason,
            ]),
            'pdf_url' => $batch->status === BulkShippingLabelBatch::STATUS_READY
                ? url("/api/sales/shipping-labels/bulk/{$batch->id}/pdf")
                : null,
        ]);
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

        return response()->json(['batch_id' => $newBatch->id], 202);
    }
}
