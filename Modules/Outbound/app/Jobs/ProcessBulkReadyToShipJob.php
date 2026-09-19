<?php

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Models\BulkRtsBatch;
use Modules\Outbound\Models\BulkRtsItem;
use Modules\Outbound\Services\OutboundFulfillmentService;

class ProcessBulkReadyToShipJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];
    public int $timeout = 600;

    public int $uniqueFor = 720;

    private const PROCESSING_STALE_AFTER_SECONDS = 720;

    public function __construct(
        public string $batchId,
    ) {
        $this->onQueue(config('queue.names.fulfillment', 'fulfillment'));
    }

    public function uniqueId(): string
    {
        return "bulk-ready-to-ship:{$this->batchId}";
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("bulk-ready-to-ship:{$this->batchId}"))
                ->releaseAfter(30)
                ->expireAfter(660),
        ];
    }

    public function handle(OutboundFulfillmentService $fulfillmentService): void
    {

        $batch = BulkRtsBatch::find($this->batchId);
        if (! $batch) {
            return;
        }

        $query = $batch->items()
            ->where(function ($query): void {
                $query->where('status', BulkRtsItem::STATUS_PENDING)
                    ->orWhere(function ($query): void {
                        $query->where('status', BulkRtsItem::STATUS_PROCESSING)
                            ->where('updated_at', '<=', now()->subSeconds(self::PROCESSING_STALE_AFTER_SECONDS));
                    });
            });

        if (! $query->exists()) {
            $batch->recomputeCounts();
            return;
        }

        $items = $query->cursor();

        foreach ($items->chunk(10) as $chunk) {

            foreach ($chunk as $item) {
                $item = $this->claimItem($item->id);
                if (! $item) {
                    continue;
                }

                try {
                    $results = $fulfillmentService->readyToShip([(string) $item->order_id]);
                    $result = $results[0] ?? [
                        'status' => 'failed',
                        'message' => 'Tidak ada respon dari dispatcher RTS.',
                    ];

                    $status = match (strtolower((string) ($result['status'] ?? 'failed'))) {
                        'success', 'queued' => BulkRtsItem::STATUS_SUCCESS,
                        'skipped' => BulkRtsItem::STATUS_SKIPPED,
                        default => BulkRtsItem::STATUS_FAILED,
                    };

                    $item->update([
                        'status' => $status,
                        'message' => $result['message'] ?? null,
                        'processed_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('ProcessBulkReadyToShipJob: per-order RTS error', [
                        'batch_id' => $this->batchId,
                        'order_id' => $item->order_id,
                        'error' => $e->getMessage(),
                    ]);

                    $item->update([
                        'status' => BulkRtsItem::STATUS_FAILED,
                        'message' => $e->getMessage(),
                        'processed_at' => now(),
                    ]);
                }
            }

            $batch->recomputeCounts();
        }

        $batch->recomputeCounts();
    }

    /**
     * Claim one item durably before the external RTS side effect. Cache locks
     * reduce duplicate dispatches, but this row lock remains authoritative if
     * a Redis lock expires or is evicted.
     */
    private function claimItem(string $itemId): ?BulkRtsItem
    {
        return DB::transaction(function () use ($itemId): ?BulkRtsItem {
            $item = BulkRtsItem::query()->lockForUpdate()->find($itemId);
            if (! $item) {
                return null;
            }

            $claimable = $item->status === BulkRtsItem::STATUS_PENDING
                || ($item->status === BulkRtsItem::STATUS_PROCESSING
                    && $item->updated_at?->lte(now()->subSeconds(self::PROCESSING_STALE_AFTER_SECONDS)));

            if (! $claimable) {
                return null;
            }

            $item->update(['status' => BulkRtsItem::STATUS_PROCESSING]);

            return $item->fresh();
        });
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessBulkReadyToShipJob permanent failure', [
            'batch_id' => $this->batchId,
            'error' => $exception->getMessage(),
        ]);

        $batch = BulkRtsBatch::find($this->batchId);
        if ($batch) {
            $batch->items()
                ->whereIn('status', [BulkRtsItem::STATUS_PENDING, BulkRtsItem::STATUS_PROCESSING])
                ->update([
                    'status' => BulkRtsItem::STATUS_FAILED,
                    'message' => 'Job pemrosesan bulk RTS terhenti: ' . mb_substr($exception->getMessage(), 0, 250),
                    'processed_at' => now(),
                ]);

            $batch->recomputeCounts();
        }
    }
}
