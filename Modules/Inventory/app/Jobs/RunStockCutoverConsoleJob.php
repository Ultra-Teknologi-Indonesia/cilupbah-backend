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

        try {
            $results = [];
            $isApply = $job->type === 'apply';
            $hasBlocking = false;
            Storage::disk('local')->makeDirectory("stock-cutover-console/{$job->id}");

            foreach (($job->files ?? []) as $locationCode => $file) {
                $path = Storage::disk((string) $file['disk'])->path((string) $file['path']);
                $reportExport = Storage::disk('local')->path("stock-cutover-console/{$job->id}/{$locationCode}-report.csv");
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
                $results[$locationCode] = [
                    'exit_code' => $exitCode,
                    'blocking' => $blocking,
                    'report_csv' => "stock-cutover-console/{$job->id}/{$locationCode}-report.csv",
                    'output' => $output,
                    'source_sha256' => hash_file('sha256', $path),
                ];
                $hasBlocking = $hasBlocking || $blocking;

                if ($exitCode !== 0) {
                    throw new \RuntimeException("Proses {$locationCode} gagal. Tidak melanjutkan gudang berikutnya.");
                }
            }

            $reportPath = "stock-cutover-console/{$job->id}/report.json";
            Storage::disk('local')->put($reportPath, json_encode([
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
                'report_disk' => 'local',
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
        }
    }
}
