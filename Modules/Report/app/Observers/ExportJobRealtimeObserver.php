<?php

namespace Modules\Report\Observers;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Modules\Report\Models\ExportJob;
use Modules\Report\Support\ExportCatalog;

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
            'file_size',
            'file_purged_at',
            'error',
        ];

        if (array_intersect($changed, $watched) === []) {
            return;
        }

        $safeError = null;
        if ($job->isFailed()) {
            $safeError = str_starts_with((string) $job->error, 'PDF dibatasi')
                ? $job->error
                : 'Gagal membuat berkas export. Coba lagi atau persempit rentang data.';
        }

        app(RealtimeEventPublisher::class)->publish(
            (string) $job->user_id,
            'export:'.$job->id,
            'export.progress',
            [
                'export_id' => (string) $job->id,
                'type' => $job->type,
                'label' => ExportCatalog::label($job->type),
                'status' => $job->effectiveStatus(),
                'file_name' => $job->file_name,
                'file_size' => $job->file_size,
                'file_available' => $job->isReady() && $job->file_path !== null && $job->file_purged_at === null,
                'error' => $safeError,
                'finished_at' => $job->finished_at?->toIso8601String(),
            ],
        );
    }
}
