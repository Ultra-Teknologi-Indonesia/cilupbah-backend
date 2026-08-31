<?php

namespace Modules\Report\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Modules\Report\Services\MonitorStockReportService;
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
        $fileName = $manager->filename($job->type, $params);
        $diskName = config('filesystems.disks.documents') ? 'documents' : config('filesystems.default', 'local');
        $extension = $job->type === 'monitor-stock-pdf' ? 'pdf' : 'xlsx';
        $path = "exports/{$job->id}.{$extension}";

        if ($job->type === 'monitor-stock-pdf') {
            $bytes = app(MonitorStockReportService::class)->pdfBytes($params);
            Storage::disk($diskName)->put($path, $bytes);
        } else {
            $export = $manager->build($job->type, $params);
            Excel::store($export, $path, $diskName);
        }

        $job->update([
            'status' => ExportJob::STATUS_READY,
            'file_disk' => $diskName,
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
