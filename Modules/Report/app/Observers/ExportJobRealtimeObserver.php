<?php

namespace Modules\Report\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Modules\Report\Models\ExportJob;

final class ExportJobRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function updated(ExportJob $job): void
    {
        $changed = array_keys($job->getChanges());
        $watched = [
            'status',
            'started_at',
            'finished_at',
            'file_path',
            'file_name',
            'file_purged_at',
            'error',
        ];

        if (array_intersect($changed, $watched) === []) {
            return;
        }

        app(RealtimeEventPublisher::class)->publish(
            (string) $job->user_id,
            'export:'.$job->id,
            'export.progress',
            [
                'export_id' => (string) $job->id,
                'type' => $job->type,
                'status' => $job->status,
                'file_name' => $job->file_name,
                'file_available' => $job->file_path !== null && $job->file_purged_at === null,
                'error' => $job->isFailed() ? $job->error : null,
                'finished_at' => $job->finished_at?->toIso8601String(),
            ],
        );
    }
}
