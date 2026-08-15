<?php

namespace Modules\Report\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Throwable;

class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 2;

    public function __construct(public string $exportJobId)
    {

        $this->onQueue('downloads');
    }

    public function handle(ExportManager $manager): void
    {
        $job = ExportJob::find($this->exportJobId);

        if (! $job || $job->status === ExportJob::STATUS_READY) {
            return;
        }

        $job->update([
            'status' => ExportJob::STATUS_PROCESSING,
            'started_at' => $job->started_at ?? now(),
        ]);

        $params = $job->params ?? [];
        $export = $manager->build($job->type, $params);
        $fileName = $manager->filename($job->type, $params);
        $targetDir = storage_path('app/private/exports');
        if (! is_dir($targetDir)) {
            @mkdir($targetDir, 0777, true);
        }
        $path = "exports/{$job->id}.xlsx";

        Excel::store($export, $path, 'local');

        $job->update([
            'status' => ExportJob::STATUS_READY,
            'file_disk' => 'local',
            'file_path' => $path,
            'file_name' => $fileName,
            'finished_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $job = ExportJob::find($this->exportJobId);

        if ($job && $job->status !== ExportJob::STATUS_READY) {
            $job->update([
                'status' => ExportJob::STATUS_FAILED,
                'error' => substr($e->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }
    }
}
