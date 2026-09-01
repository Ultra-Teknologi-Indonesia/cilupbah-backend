<?php

namespace Modules\Report\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Modules\Report\Services\MonitorStockReportService;
use Throwable;

class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(public string $exportJobId)
    {

        $this->onConnection(config('exports.connection', 'redis-long'));
        $this->onQueue(config('exports.queue', 'exports'));
    }

    public function handle(ExportManager $manager): void
    {
        ini_set('memory_limit', (string) config('exports.memory_limit', '1536M'));
        set_time_limit((int) config('exports.timeout', 900));

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
        $extension = in_array($job->type, ['monitor-stock-pdf', 'picklist-pdf'], true)
            ? 'pdf'
            : 'xlsx';
        $path = "exports/{$job->id}.{$extension}";

        Log::info('export.started', [
            'export_id' => $job->id,
            'type' => $job->type,
            'memory_limit' => ini_get('memory_limit'),
        ]);

        if (in_array($job->type, ['monitor-stock-pdf', 'picklist-pdf'], true)) {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'cilupbah-export-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('Tidak dapat membuat berkas sementara untuk export PDF.');
            }

            try {
                if ($job->type === 'monitor-stock-pdf') {
                    app(MonitorStockReportService::class)->writePdf($params, $temporaryPath);
                } else {
                    app(\Modules\Outbound\Services\PicklistPdfExportService::class)
                        ->write((string) ($params['picklist_id'] ?? ''), $temporaryPath);
                }
                $stream = fopen($temporaryPath, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Tidak dapat membaca hasil export PDF.');
                }

                try {
                    Storage::disk($diskName)->put($path, $stream);
                } finally {
                    fclose($stream);
                }
            } finally {
                @unlink($temporaryPath);
            }
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

        Log::info('export.finished', [
            'export_id' => $job->id,
            'type' => $job->type,
            'duration_seconds' => $job->finished_at?->diffInSeconds($job->started_at),
            'peak_memory_bytes' => memory_get_peak_usage(true),
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

        Log::error('export.failed', [
            'export_id' => $this->exportJobId,
            'error' => $e->getMessage(),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    }
}
