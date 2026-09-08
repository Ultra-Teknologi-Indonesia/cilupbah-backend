<?php

declare(strict_types=1);

namespace Modules\Inventory\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Modules\Inventory\Models\StockCutoverConsoleJob;
use Throwable;

final class RunStockCutoverConsoleJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $consoleJobId)
    {
        $this->onConnection((string) config('operations.stock_cutover_console.queue_connection', 'redis-long'));
        $this->onQueue((string) config('operations.stock_cutover_console.queue', 'stock-cutover'));
    }

    public function handle(): void
    {
        $job = StockCutoverConsoleJob::find($this->consoleJobId);
        if (! $job || $job->status === StockCutoverConsoleJob::STATUS_READY) {
            return;
        }

        ini_set('memory_limit', '1024M');
        set_time_limit($this->timeout);

        $job->update([
            'status' => StockCutoverConsoleJob::STATUS_PROCESSING,
            'started_at' => $job->started_at ?? now(),
            'error' => null,
        ]);

        $workingDirectory = "stock-cutover-console-tmp/{$job->id}";

        try {
            $results = [];
            $isApply = $job->type === 'apply';
            $hasBlocking = false;
            $reportDiskName = (string) config(
                'operations.stock_cutover_console.report_disk',
                config('operations.stock_cutover_console.upload_disk', 's3'),
            );
            $localDisk = Storage::disk('local');
            $localDisk->makeDirectory($workingDirectory);

            foreach (($job->files ?? []) as $locationCode => $file) {
                $path = $this->materializeSourceFile($file, $workingDirectory, (string) $locationCode);
                $reportExport = $localDisk->path("{$workingDirectory}/{$locationCode}-report.csv");
                $arguments = [
                    'file' => $path,
                    '--location' => (string) $locationCode,
                    '--zero-missing' => true,
                    '--export' => $reportExport,
                ];

                if ($isApply) {
                    $previewExit = Artisan::call('inventory:import-baseline', $arguments);
                    $previewOutput = Artisan::output();
                    if ($previewExit !== 0 || str_contains($previewOutput, 'Terdapat ') || str_contains($previewOutput, 'DITOLAK')) {
                        throw new \RuntimeException("Apply {$locationCode} dibatalkan karena validasi ulang masih menemukan masalah.");
                    }
                    $arguments['--commit'] = true;
                }

                $exitCode = Artisan::call('inventory:import-baseline', $arguments);
                $output = Artisan::output();
                $blocking = str_contains($output, 'Terdapat ')
                    || str_contains($output, 'baris bermasalah');
                $reportPath = "stock-cutover-console/{$job->id}/{$locationCode}-report.csv";
                $this->storeTemporaryReport($reportDiskName, $reportPath, $reportExport);
                $results[$locationCode] = [
                    'exit_code' => $exitCode,
                    'blocking' => $blocking,
                    'report_csv' => $reportPath,
                    'output' => $output,
                    'source_sha256' => hash_file('sha256', $path),
                ];
                $hasBlocking = $hasBlocking || $blocking;

                if ($exitCode !== 0) {
                    throw new \RuntimeException("Proses {$locationCode} gagal. Tidak melanjutkan gudang berikutnya.");
                }
            }

            $reportPath = "stock-cutover-console/{$job->id}/report.json";
            Storage::disk($reportDiskName)->put($reportPath, json_encode([
                'mode' => $isApply ? 'APPLY' : 'DRY_RUN',
                'stock_source' => 'Qty Aktual',
                'zero_missing' => true,
                'blocking' => $hasBlocking,
                'results' => $results,
                'finished_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            $job->update([
                'status' => StockCutoverConsoleJob::STATUS_READY,
                'report' => ['blocking' => $hasBlocking, 'results' => $results],
                'report_disk' => $reportDiskName,
                'report_path' => $reportPath,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => StockCutoverConsoleJob::STATUS_FAILED,
                'error' => mb_substr($exception->getMessage(), 0, 4000),
                'finished_at' => now(),
            ]);

            throw $exception;
        } finally {
            Storage::disk('local')->deleteDirectory($workingDirectory);
        }
    }

    private function materializeSourceFile(array $file, string $workingDirectory, string $locationCode): string
    {
        $diskName = (string) ($file['disk'] ?? '');
        $sourcePath = (string) ($file['path'] ?? '');

        if ($diskName === '' || $sourcePath === '') {
            throw new \RuntimeException("File sumber {$locationCode} tidak lengkap.");
        }

        $sourceDisk = Storage::disk($diskName);
        if (! $sourceDisk->exists($sourcePath)) {
            throw new \RuntimeException("File sumber {$locationCode} tidak ditemukan di penyimpanan.");
        }

        $localPath = Storage::disk('local')->path("{$workingDirectory}/{$locationCode}-source.xlsx");
        $input = $sourceDisk->readStream($sourcePath);
        $output = fopen($localPath, 'wb');

        if (! is_resource($input) || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            throw new \RuntimeException("File sumber {$locationCode} tidak dapat disiapkan untuk diproses.");
        }

        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new \RuntimeException("File sumber {$locationCode} gagal disalin untuk diproses.");
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        $expectedHash = (string) ($file['sha256'] ?? '');
        if ($expectedHash !== '' && ! hash_equals($expectedHash, (string) hash_file('sha256', $localPath))) {
            throw new \RuntimeException("Integritas file sumber {$locationCode} tidak cocok; proses dibatalkan.");
        }

        return $localPath;
    }

    private function storeTemporaryReport(string $diskName, string $destination, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Laporan CSV tidak dapat dibaca untuk disimpan.');
        }

        try {
            if (! Storage::disk($diskName)->writeStream($destination, $stream)) {
                throw new \RuntimeException('Laporan CSV tidak dapat disimpan ke object storage.');
            }
        } finally {
            fclose($stream);
        }
    }
}
