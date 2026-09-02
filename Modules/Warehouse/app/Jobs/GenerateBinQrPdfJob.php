<?php

namespace Modules\Warehouse\Jobs;

use App\Services\QrCodeGenerator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\QrPrintJob;
use Modules\Warehouse\Services\BinQrPrintService;
use setasign\Fpdi\Fpdi;
use Throwable;

class GenerateBinQrPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    private const CHUNK_SIZE = 100;

    public function __construct(public readonly string $jobId)
    {
        $this->onConnection(config('queue.routing.qr_labels.connection', 'redis-long'));
        $this->onQueue(config('queue.routing.qr_labels.queue', 'qr-labels'));
    }

    public function handle(QrCodeGenerator $qrCodeGenerator): void
    {
        ini_set('memory_limit', (string) config('queue.routing.qr_labels.memory_limit', '512M'));
        set_time_limit($this->timeout);

        $printJob = QrPrintJob::find($this->jobId);
        if (! $printJob) {
            Log::warning('GenerateBinQrPdfJob: job tidak ditemukan', ['job_id' => $this->jobId]);

            return;
        }

        if (in_array($printJob->status, [QrPrintJob::STATUS_READY, QrPrintJob::STATUS_FAILED], true)) {
            return;
        }

        $location = Location::find($printJob->location_id);
        if (! $location) {
            $printJob->update([
                'status' => QrPrintJob::STATUS_FAILED,
                'error_message' => 'Lokasi tidak ditemukan.',
                'completed_at' => now(),
            ]);

            return;
        }

        $printJob->update([
            'status' => QrPrintJob::STATUS_PROCESSING,
            'started_at' => now(),
            'processed_bins' => 0,
        ]);

        $temporaryFiles = [];

        try {
            $paper = $printJob->paper;
            $qrSize = $this->qrSizeFor($paper);

            $query = LocationBin::where('location_id', $printJob->location_id)
                ->whereNotNull('bin_final_code')
                ->where('bin_final_code', '!=', '')
                ->orderBy('bin_final_code');

            $binIds = $printJob->bin_ids;
            if (is_array($binIds) && count($binIds) > 0) {
                $query->whereIn('id', $binIds);
            }

            $mergedPdf = new Fpdi('P', 'mm');
            $hasPages = false;
            $processed = 0;

            $query->chunk(self::CHUNK_SIZE, function ($chunk) use (&$mergedPdf, &$hasPages, &$temporaryFiles, &$processed, $qrSize, $printJob, $qrCodeGenerator, $location, $paper) {
                $items = [];

                foreach ($chunk as $bin) {
                    $code = (string) $bin->bin_final_code;
                    if ($code === '') {
                        continue;
                    }
                    $items[] = [
                        'bin_final_code' => $code,
                        'qr_data_uri' => $qrCodeGenerator->svgDataUri($code, $qrSize),
                    ];
                }

                if (! empty($items)) {
                    $chunkPath = $this->temporaryPdfPath();
                    $temporaryFiles[] = $chunkPath;

                    $chunkPdf = Pdf::loadView('warehouse::pdf.bin-qr', [
                        'location' => $location,
                        'items' => $items,
                        'paper' => $paper,
                    ]);
                    $this->applyPaperSettings($chunkPdf, $paper);
                    $chunkPdf->save($chunkPath);

                    $this->appendPdfFile($mergedPdf, $chunkPath);
                    $hasPages = true;
                    @unlink($chunkPath);
                    $processed += count($items);
                    unset($chunkPdf, $items);
                    gc_collect_cycles();
                }

                $printJob->update(['processed_bins' => $processed]);
            });

            $relPath = BinQrPrintService::storagePathFor($printJob->id);
            $disk = Storage::disk(BinQrPrintService::STORAGE_DISK);

            $mergedPath = $this->temporaryPdfPath();
            $temporaryFiles[] = $mergedPath;

            if (! $hasPages) {
                $emptyPdf = Pdf::loadView('warehouse::pdf.bin-qr', [
                    'location' => $location,
                    'items' => [],
                    'paper' => $paper,
                ]);
                $this->applyPaperSettings($emptyPdf, $paper);
                $emptyPdf->save($mergedPath);
                unset($emptyPdf);
            } else {
                $mergedPdf->Output('F', $mergedPath);
            }
            unset($mergedPdf);

            $stream = fopen($mergedPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('PDF QR hasil generate tidak dapat dibaca.');
            }

            try {
                if (! $disk->put($relPath, $stream)) {
                    throw new \RuntimeException('PDF QR gagal disimpan ke storage.');
                }
            } finally {
                fclose($stream);
            }

            $printJob->update([
                'status' => QrPrintJob::STATUS_READY,
                'processed_bins' => $processed,
                'total_bins' => max($printJob->total_bins, $processed),
                'file_path' => $relPath,
                'completed_at' => now(),
            ]);

            Log::info('GenerateBinQrPdfJob: selesai', [
                'job_id' => $printJob->id,
                'location_id' => $printJob->location_id,
                'total' => $processed,
                'size_bytes' => $disk->size($relPath),
            ]);
        } catch (Throwable $e) {
            Log::error('GenerateBinQrPdfJob: gagal', [
                'job_id' => $printJob->id,
                'exception' => $e->getMessage(),
            ]);
            $printJob->update([
                'status' => QrPrintJob::STATUS_FAILED,
                'error_message' => substr($e->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);
            throw $e;
        } finally {
            foreach ($temporaryFiles as $temporaryFile) {
                if (is_file($temporaryFile)) {
                    @unlink($temporaryFile);
                }
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $printJob = QrPrintJob::find($this->jobId);
        if ($printJob && $printJob->status !== QrPrintJob::STATUS_READY) {
            $printJob->update([
                'status' => QrPrintJob::STATUS_FAILED,
                'error_message' => substr($exception->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);
        }
    }

    private function applyPaperSettings($pdf, string $paper): void
    {
        switch ($paper) {
            case 'thermal_50x40':
                $pdf->setPaper([0, 0, 141.7, 113.4], 'portrait');
                break;
            case 'thermal_80x40':
                $pdf->setPaper([0, 0, 226.8, 113.4], 'portrait');
                break;
            default:
                $pdf->setPaper('a4', 'portrait');
                break;
        }
    }

    private function qrSizeFor(string $paper): int
    {
        return match ($paper) {
            'thermal_50x40' => 200,
            'thermal_80x40' => 220,
            'a4_single' => 600,
            'a4_multi' => 220,
            default => 200,
        };
    }

    private function temporaryPdfPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qr-labels-');
        if ($path === false) {
            throw new \RuntimeException('Temporary file PDF QR tidak dapat dibuat.');
        }

        @unlink($path);

        return $path.'.pdf';
    }

    private function appendPdfFile(Fpdi $merged, string $chunkPath): void
    {
        $pageCount = $merged->setSourceFile($chunkPath);

        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $merged->importPage($page);
            $size = $merged->getTemplateSize($template);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

            $merged->AddPage($orientation, [$size['width'], $size['height']]);
            $merged->useTemplate($template);
        }
    }
}
