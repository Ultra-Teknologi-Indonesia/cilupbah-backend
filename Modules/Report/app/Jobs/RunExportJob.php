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
use Modules\Inventory\Services\TransferBulkPdfExportService;
use Modules\Inventory\Services\PutawayBulkPdfExportService;
use Modules\Inventory\Services\StockAdjustmentBulkPdfExportService;
use Modules\Outbound\Services\PicklistBulkPdfExportService;
use Modules\Outbound\Services\ManifestBulkPdfExportService;
use Modules\Sales\Services\BulkInvoiceService;
use Modules\Outbound\Services\PicklistPdfExportService;
use Modules\Report\Models\ExportJob;
use Modules\Report\Services\ExportManager;
use Modules\Report\Services\MonitorStockReportService;
use Throwable;

class RunExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 2;

    public array $backoff = [30, 120];

    public bool $failOnTimeout = true;

    public function __construct(
        public string $exportJobId,
        ?string $connection = null,
        ?string $queue = null,
    ) {

        $this->onConnection($connection ?? config('exports.connection', 'redis-long'));
        $this->onQueue($queue ?? config('exports.queue', 'exports'));
    }

    public function handle(ExportManager $manager): void
    {
        $job = ExportJob::find($this->exportJobId);

        if (! $job || $job->status === ExportJob::STATUS_READY) {
            return;
        }

        $routing = $manager->routingFor($job->type);
        $profile = $routing['profile'];
        $memoryLimit = match ($profile) {
            'catalog' => config('exports.catalog_memory_limit', '512M'),
            'pdf' => config('exports.pdf_memory_limit', '1536M'),
            default => config('exports.sheet_memory_limit', config('exports.memory_limit', '768M')),
        };
        $timeout = match ($profile) {
            'catalog' => (int) config('exports.catalog_timeout', 600),
            'pdf' => (int) config('exports.pdf_timeout', 900),
            default => (int) config('exports.sheet_timeout', config('exports.timeout', 720)),
        };
        ini_set('memory_limit', (string) $memoryLimit);
        set_time_limit($timeout);

        $job->update([
            'status' => ExportJob::STATUS_PROCESSING,
            'started_at' => $job->started_at ?? now(),
        ]);

        $params = $job->params ?? [];
        $fileName = $manager->filename($job->type, $params);
        $diskName = config('filesystems.disks.documents') ? 'documents' : config('filesystems.default', 'local');
        $pdfTypes = ExportManager::PDF_TYPES;
        $csvTypes = ['product-catalog-csv', 'purchase-order-list', 'purchase-order-detail'];
        $extension = in_array($job->type, $pdfTypes, true)
            ? 'pdf'
            : (in_array($job->type, $csvTypes, true) ? 'csv' : 'xlsx');
        $path = "exports/{$job->id}.{$extension}";

        Log::info('export.started', [
            'export_id' => $job->id,
            'type' => $job->type,
            'profile' => $profile,
            'queue' => $job->queue_name ?? $routing['queue'],
            'memory_limit' => ini_get('memory_limit'),
        ]);

        if (in_array($job->type, $pdfTypes, true)) {
            $temporaryPath = tempnam(sys_get_temp_dir(), 'cilupbah-export-');
            if ($temporaryPath === false) {
                throw new \RuntimeException('Tidak dapat membuat berkas sementara untuk export PDF.');
            }

            try {
                if ($job->type === 'monitor-stock-pdf') {
                    app(MonitorStockReportService::class)->writePdf($params, $temporaryPath);
                } elseif ($job->type === 'picklist-pdf') {
                    app(PicklistPdfExportService::class)
                        ->write((string) ($params['picklist_id'] ?? ''), $temporaryPath);
                } elseif ($job->type === 'transfer-out-bulk-pdf') {
                    app(TransferBulkPdfExportService::class)
                        ->write((array) ($params['ids'] ?? []), $temporaryPath);
                } elseif ($job->type === 'putaway-bulk-pdf') {
                    app(PutawayBulkPdfExportService::class)->write(
                        (array) ($params['ids'] ?? []),
                        $temporaryPath,
                        (string) ($params['printed_by'] ?? '-'),
                    );
                } elseif ($job->type === 'stock-adjustment-bulk-pdf') {
                    app(StockAdjustmentBulkPdfExportService::class)->write((array) ($params['ids'] ?? []), $temporaryPath);
                } elseif ($job->type === 'picklist-bulk-pdf') {
                    app(PicklistBulkPdfExportService::class)->write((array) ($params['order_ids'] ?? []), $temporaryPath);
                } elseif ($job->type === 'manifest-bulk-pdf') {
                    app(ManifestBulkPdfExportService::class)->write((array) ($params['order_ids'] ?? []), $temporaryPath);
                } elseif ($job->type === 'invoice-bulk-pdf') {
                    app(BulkInvoiceService::class)->write((array) ($params['order_ids'] ?? []), $temporaryPath);
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
            $writerType = in_array($job->type, $csvTypes, true)
                ? \Maatwebsite\Excel\Excel::CSV
                : null;

            if ($writerType === null) {
                Excel::store($export, $path, $diskName);
            } else {
                Excel::store($export, $path, $diskName, $writerType);
            }
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
