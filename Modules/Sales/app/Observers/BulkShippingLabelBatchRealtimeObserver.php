<?php

namespace Modules\Sales\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Modules\Sales\Models\BulkShippingLabelBatch;

final class BulkShippingLabelBatchRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(BulkShippingLabelBatch $batch): void
    {
        $changed = array_keys($batch->getChanges());
        $watched = [
            'status',
            'started_at',
            'finished_at',
            'done_count',
            'failed_count',
            'skipped_count',
            'merged_pdf_path',
            'print_pdf_path',
            'file_purged_at',
        ];

        if (array_intersect($changed, $watched) === []) {
            return;
        }

        app(RealtimeEventPublisher::class)->publish(
            (string) $batch->user_id,
            'bulk-label:'.$batch->id,
            'bulk-label.progress',
            [
                'batch_id' => (string) $batch->id,
                'status' => $batch->status,
                'total' => (int) $batch->total_count,
                'done' => (int) $batch->done_count,
                'failed' => (int) $batch->failed_count,
                'skipped' => (int) $batch->skipped_count,
                'file_available' => $batch->file_purged_at === null
                    && ($batch->merged_pdf_path !== null || $batch->print_pdf_path !== null),
                'finished_at' => $batch->finished_at?->toIso8601String(),
            ],
        );
    }
}
