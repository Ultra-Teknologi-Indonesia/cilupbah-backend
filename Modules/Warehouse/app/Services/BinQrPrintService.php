<?php

namespace Modules\Warehouse\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Warehouse\Jobs\GenerateBinQrPdfJob;
use Modules\Warehouse\Models\Location;
use Modules\Warehouse\Models\LocationBin;
use Modules\Warehouse\Models\QrPrintJob;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BinQrPrintService
{
    public const array VALID_PAPERS = [
        'thermal_50x40',
        'thermal_80x40',
        'a4_single',
        'a4_multi',
    ];

    public const string STORAGE_DISK = 's3';

    public const string STORAGE_DIR = 'qr-jobs';

    public function binsQueryForPrint(string $locationId, ?array $binIds = null)
    {
        $query = LocationBin::where('location_id', $locationId)
            ->whereNotNull('bin_final_code')
            ->where('bin_final_code', '!=', '');

        if (! empty($binIds)) {
            $query->whereIn('id', $binIds);
        }

        return $query;
    }

    public function binsForPrint(string $locationId, ?array $binIds): array
    {
        $query = $this->binsQueryForPrint($locationId, $binIds);
        $total = (clone $query)->count();
        $bins = $query->orderBy('bin_final_code')->get();

        return ['total' => $total, 'bins' => $bins];
    }

    public function createJob(string $locationId, array $opts = []): QrPrintJob
    {
        $location = Location::find($locationId);
        if (! $location) {
            throw new ModelNotFoundException('Lokasi tidak ditemukan.');
        }

        $paper = $opts['paper'] ?? 'thermal_50x40';
        if (! in_array($paper, self::VALID_PAPERS, true)) {
            $paper = 'thermal_50x40';
        }

        $binIds = isset($opts['bin_ids']) && is_array($opts['bin_ids'])
            ? array_values(array_filter(array_map('strval', $opts['bin_ids'])))
            : null;

        $totalBins = (int) $this->binsQueryForPrint($locationId, $binIds)->count();

        $job = QrPrintJob::create([
            'id' => (string) Str::uuid(),
            'location_id' => $locationId,
            'user_id' => $opts['user_id'] ?? null,
            'status' => QrPrintJob::STATUS_PENDING,
            'paper' => $paper,
            'bin_ids' => $binIds,
            'total_bins' => $totalBins,
            'processed_bins' => 0,
        ]);

        GenerateBinQrPdfJob::dispatch($job->id);

        return $job;
    }

    public function getJobStatus(string $jobId): array
    {
        $job = QrPrintJob::findOrFail($jobId);

        return [
            'id' => $job->id,
            'location_id' => $job->location_id,
            'status' => $job->status,
            'paper' => $job->paper,
            'progress' => [
                'processed' => $job->processed_bins,
                'total' => $job->total_bins,
                'percent' => $job->progressPercent(),
            ],
            'error_message' => empty($job->error_message) ? null : \App\Support\FriendlyError::generic($job->error_message, 'Gagal membuat PDF label rak. Coba lagi.'),
            'started_at' => optional($job->started_at)->toIso8601String(),
            'completed_at' => optional($job->completed_at)->toIso8601String(),
            'download_url' => $job->status === QrPrintJob::STATUS_READY
                ? route('api.warehouse.qr-jobs.download', ['id' => $job->id])
                : null,
        ];
    }

    public function downloadJobPdf(string $jobId): BinaryFileResponse
    {
        $job = QrPrintJob::findOrFail($jobId);

        if ($job->status !== QrPrintJob::STATUS_READY || ! $job->file_path) {
            abort(425, 'PDF belum siap.');
        }

        $disk = Storage::disk(self::STORAGE_DISK);
        if (! $disk->exists($job->file_path)) {
            abort(404, 'File PDF tidak ditemukan (mungkin sudah dihapus).');
        }

        $abs = $disk->path($job->file_path);
        $filename = 'qr-rak-'.substr($job->id, 0, 8).'.pdf';

        return response()->download($abs, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public static function storagePathFor(string $jobId): string
    {
        return self::STORAGE_DIR.'/'.$jobId.'.pdf';
    }

    public function cleanupOldJobs(int $hours = 24): int
    {
        $threshold = now()->subHours($hours);
        $jobs = QrPrintJob::where('completed_at', '<', $threshold)
            ->whereNotNull('file_path')
            ->get();

        $disk = Storage::disk(self::STORAGE_DISK);
        $count = 0;
        foreach ($jobs as $job) {
            /** @var QrPrintJob $job */
            if ($disk->exists($job->file_path)) {
                $disk->delete($job->file_path);
            }
            $job->update(['file_path' => null]);
            $count++;
        }

        return $count;
    }
}
