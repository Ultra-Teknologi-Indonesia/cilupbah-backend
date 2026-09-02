<?php

declare(strict_types=1);

namespace Modules\Outbound\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Outbound\Services\ProcessOrdersCsvExportService;
use Modules\Report\Models\ExportJob;
use Throwable;

final class RunProcessOrdersCsvExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $exportJobId)
    {
        $this->onConnection(config('exports.connection', 'redis-long'));
        $this->onQueue(config('exports.queue', 'exports'));
    }

    public function backoff(): array
    {
        return [10];
    }

    public function handle(ProcessOrdersCsvExportService $exporter): void
    {
        ini_set('memory_limit', (string) config('exports.csv_memory_limit', '512M'));
        set_time_limit((int) config('exports.timeout', 900));

        $job = ExportJob::find($this->exportJobId);
        if (! $job || $job->status === ExportJob::STATUS_READY) {
            return;
        }

        $job->update([
            'status' => ExportJob::STATUS_PROCESSING,
            'started_at' => $job->started_at ?? now(),
            'error' => null,
        ]);

        $diskName = config('filesystems.disks.documents')
            ? 'documents'
            : config('filesystems.default', 'local');
        $path = "exports/{$job->id}.csv";
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cilupbah-orders-csv-');

        if ($temporaryPath === false) {
            throw new \RuntimeException('Tidak dapat membuat berkas sementara untuk export pesanan.');
        }

        Log::info('process-orders-export.started', [
            'export_id' => $job->id,
            'memory_limit' => ini_get('memory_limit'),
            'chunk_size' => config('exports.csv_chunk_size', 250),
        ]);

        try {
            $stage = (string) ($job->params['stage'] ?? '');
            $subStatus = (string) ($job->params['sub'] ?? '');
            $rowCount = $exporter->write($temporaryPath, $stage, $subStatus);
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Tidak dapat membaca hasil export pesanan.');
            }

            try {
                if (! Storage::disk($diskName)->put($path, $stream)) {
                    throw new \RuntimeException('Tidak dapat menyimpan hasil export pesanan.');
                }
            } finally {
                fclose($stream);
            }

            $job->update([
                'status' => ExportJob::STATUS_READY,
                'file_disk' => $diskName,
                'file_path' => $path,
                'file_name' => sprintf(
                    'export-pesanan-%s-%s_%s.csv',
                    $stage,
                    $subStatus,
                    now()->format('Y-m-d_His'),
                ),
                'finished_at' => now(),
            ]);

            Log::info('process-orders-export.finished', [
                'export_id' => $job->id,
                'row_count' => $rowCount,
                'duration_seconds' => now()->diffInSeconds($job->started_at),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ]);
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function failed(Throwable $exception): void
    {
        $job = ExportJob::find($this->exportJobId);

        if ($job && $job->status !== ExportJob::STATUS_READY) {
            if ($job->file_path) {
                Storage::disk($job->file_disk ?? 'local')->delete($job->file_path);
            }

            $job->update([
                'status' => ExportJob::STATUS_FAILED,
                'error' => substr($exception->getMessage(), 0, 1000),
                'finished_at' => now(),
            ]);
        }

        Log::error('process-orders-export.failed', [
            'export_id' => $this->exportJobId,
            'error' => $exception->getMessage(),
            'peak_memory_bytes' => memory_get_peak_usage(true),
        ]);
    }
}
