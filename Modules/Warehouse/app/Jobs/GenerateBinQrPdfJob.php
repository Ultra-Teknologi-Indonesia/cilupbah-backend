<?php

namespace Modules\Warehouse\Jobs;

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
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use Throwable;

/**
 * Generate PDF QR rak secara async + update progress per chunk.
 * Strategi: render satu blade dengan loop semua bin (page-break per item).
 * Progress di-update setiap 100 bin lewat callback chunk Eloquent.
 * Output: file PDF di storage local (qr-jobs/{job_id}.pdf).
 */
class GenerateBinQrPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800; // 30 menit; PDF besar bisa lama.

    private const CHUNK_SIZE = 100;

    public function __construct(public readonly string $jobId) {}

    public function handle(): void
    {
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

            $items = [];
            $processed = 0;

            $query->chunk(self::CHUNK_SIZE, function ($chunk) use (&$items, &$processed, $qrSize, $printJob) {
                foreach ($chunk as $bin) {
                    $code = (string) $bin->bin_final_code;
                    if ($code === '') {
                        continue;
                    }
                    $items[] = [
                        'bin_final_code' => $code,
                        'qr_data_uri' => $this->generateQrDataUri($code, $qrSize),
                    ];
                    $processed++;
                }
                $printJob->update(['processed_bins' => $processed]);
            });

            $pdf = Pdf::loadView('warehouse::pdf.bin-qr', [
                'location' => $location,
                'items' => $items,
                'paper' => $paper,
            ]);
            $this->applyPaperSettings($pdf, $paper);

            $relPath = BinQrPrintService::storagePathFor($printJob->id);
            $disk = Storage::disk(BinQrPrintService::STORAGE_DISK);
            $disk->put($relPath, $pdf->output());

            $printJob->update([
                'status' => QrPrintJob::STATUS_READY,
                'processed_bins' => count($items),
                'total_bins' => max($printJob->total_bins, count($items)),
                'file_path' => $relPath,
                'completed_at' => now(),
            ]);

            Log::info('GenerateBinQrPdfJob: selesai', [
                'job_id' => $printJob->id,
                'location_id' => $printJob->location_id,
                'total' => count($items),
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

    private function generateQrDataUri(string $content, int $size): ?string
    {
        try {
            $svg = QrCode::format('svg')
                ->size($size)
                ->margin(0)
                ->errorCorrection('M')
                ->generate($content);

            return 'data:image/svg+xml;base64,'.base64_encode((string) $svg);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
